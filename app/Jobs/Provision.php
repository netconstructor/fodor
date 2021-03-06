<?php

namespace App\Jobs;

use App\Fodor\Config;
use App\Fodor\Github;
use App\Fodor\Repo;
use App\Jobs\Job;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;

use DigitalOceanV2\Adapter\GuzzleHttpAdapter;
use DigitalOceanV2\DigitalOceanV2;

use Illuminate\Support\Facades\Redis;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

ini_set("auto_detect_line_endings", true);

class Provision extends Job implements ShouldQueue
{
    use InteractsWithQueue, SerializesModels;

    private $provision;
    private $inputs;
    private $log;
    private $exitCode = 88;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(\App\Provision $provision)
    {
        $this->provision = $provision;
        $this->inputs = $this->provision->inputs;
    }


    public function packet_handler($str)
    {
        echo $str;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $this->log = new Logger('LOG');
        $this->log->pushHandler(new StreamHandler(storage_path('logs/provision/' . $this->provision->uuid . '.log'), Logger::INFO));

        $logProvisionerOutput = new Logger('OUTPUT');
        $logProvisionerOutput->pushHandler(new StreamHandler(storage_path('logs/provision/' . $this->provision->uuid . '.output'), Logger::INFO));

        $this->log->addInfo("Provisioning started");

        $uuid = $this->provision->uuid;
        $sshKeys = new \App\Fodor\Ssh\Keys($uuid);

        //TODO: This should be a beanstalk job with AJAX updating
        //DO ALL THE PROVISIONING HERE - GET GITHUB FODOR.JSON, SSH IN, DO IT ALL,
        $this->provision->status = 'provision';
        $this->provision->save();
        $this->log->addInfo("Set status to provision");

        $repo = new Repo($this->provision->repo);
        $branch = $repo->getBranch();
        $username = $repo->getUsername();

        $client = new \Github\Client();
        $client->authenticate(env('GITHUB_API_TOKEN'), false, \Github\Client::AUTH_HTTP_TOKEN);
        $github = new Github($client, $repo);

        $json = $github->getFodorJson();
        $this->log->addInfo("Fetched fodor.json from GitHub: {$json}");

        $fodorJson = new Config($json);

        try {
            $fodorJson->valid();
        } catch (\Exception $e) {
            $this->log->addError('Fodor.json invalid');
        }

        $baseScript = \View::make('provision-base.ubuntu-14-04-x64',[
            'installpath' => $fodorJson->installpath,
            'name' => $this->provision->repo,
            'domain' => $this->provision->subdomain . '.fodor.xyz',
            'ipv4' => $this->provision->ipv4,
            'inputs' => $this->inputs
        ])->render();

        // Has to be less than 1mb
        $providedScript = $github->getFileContents($fodorJson->provisioner);

        if (empty($providedScript)) {
            $this->log->addError('Provisioner invalid');
        }

        $this->log->addInfo("Fetched provisioner script from GitHub: {$providedScript}");

        $remoteProvisionScriptPath = '/tmp/fodor-provision-script-' . $this->provision->uuid;

        if($ssh = ssh2_connect($this->provision->ipv4, 22, [], [
            'disconnect' => [$this, 'tidyUp']
        ])) {
            $this->log->addInfo("Successfully connected to the server via SSH: {$this->provision->ipv4}");

            if(ssh2_auth_pubkey_file($ssh, 'root', storage_path('app/' . $sshKeys->getPublicKeyPath()), storage_path('app/' . $sshKeys->getPrivateKeyPath()))) {
                $this->log->addInfo("Successfully authenticated");
                $this->log->addInfo("Running: /bin/bash '{$remoteProvisionScriptPath}'");

                $sftp = ssh2_sftp($ssh); //TODO error check and refactor all of the code we've written so far
                $stream = fopen("ssh2.sftp://{$sftp}{$remoteProvisionScriptPath}", 'w');
                $fullScript = $baseScript . PHP_EOL . $providedScript . PHP_EOL;
                fwrite($stream, $fullScript);
                fclose($stream);
                $this->log->addInfo("Transferred provisioner-combined script");

                // TODO: Investigate setting environment variables here instead of with export
                $stream = ssh2_exec($ssh, '(/bin/bash ' . escapeshellarg($remoteProvisionScriptPath) . ' 2>&1); echo -e "\n$?"');
                stream_set_blocking($stream, true);
                $lastString = '';
                while(($string = fgets($stream)) !== false) {
                    $logProvisionerOutput->addInfo($string);
                    echo $string;
                    $lastString = $string;
                }

                $this->log->addInfo('EXIT CODE: ' . $lastString);
                $this->exitCode = (int) trim($lastString);

                fclose($stream);
            } else {
                $this->log->addError("Failed to authenticate to SSH");
                exit(1);
            }
        } else {
            $this->log->addError("Failed to connect to SSH");
            $this->release(2); // Delay x seconds to retry as SSH isn't ready yet
            exit(1);
        }

        $this->tidyUp();
    }

    public function tidyUp()
    {
        $redisKey = config('rediskeys.digitalocean_token') . $this->provision->uuid;
        $sshKeys = new \App\Fodor\Ssh\Keys($this->provision->uuid);

        $adapter = new GuzzleHttpAdapter(Redis::get($redisKey));
        $digitalocean = new DigitalOceanV2($adapter);

        // ## REMOVE SSH KEYS FROM DIGITALOCEAN AND OUR LOCAL FILESYSTEM ##

        $keysFromDo = $digitalocean->key()->getAll();

        if (!empty($keysFromDo)) {
            foreach ($keysFromDo as $key) {
                if ($key->name == 'fodor-' . $this->provision->uuid) {
                    $digitalocean->key()->delete($key->id); // Remove our fodor SSH key from the users DigitalOcean account
                    $this->log->addInfo("Removed SSH key: {$key->name}: {$key->id} from DigitalOcean");
                }
            }
        }

        if (\Storage::exists($sshKeys->getPublicKeyPath())) {
            try {
                $this->log->addInfo("Removed local SSH Keys");
                $sshKeys->remove(); // uuid is the name of the file
            } catch (Exception $e) {
                // TODO: Handle.  We should probably be alerted as we don't want these lying around
            }
        }

        Redis::del($redisKey);

        $this->provision->dateready = (new \DateTime('now', new \DateTimeZone('UTC')))->format('c');
        $this->provision->exitcode = $this->exitCode;
        $this->provision->status = ($this->exitCode === 0) ? 'ready' : 'errored'; //TODO: Other distros or shells?
        $this->provision->save();

        //TODO: If it errored, an alert should be sent out for investigation

        $this->log->addInfo("Set provision row's status to {$this->provision->status}, we're done here");
    }
}
