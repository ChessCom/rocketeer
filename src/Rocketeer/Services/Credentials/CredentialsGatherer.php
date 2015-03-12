<?php
/*
 * This file is part of Rocketeer
 *
 * (c) Maxime Fabre <ehtnam6@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace Rocketeer\Services\Credentials;

use Illuminate\Support\Arr;
use Rocketeer\Traits\HasLocator;

class CredentialsGatherer
{
    use HasLocator;

    /**
     * Rules for which credentials are
     * strictly required or not.
     *
     * @type array
     */
    protected $rules = [
        'server'     => [
            'host'          => true,
            'username'      => true,
            'password'      => false,
            'keyphrase'     => false,
            'key'           => false,
            'agent'         => false,
            'agent-forward' => false,
        ],
        'repository' => [
            'repository' => true,
            'username'   => false,
            'password'   => false,
        ],
    ];

    /**
     * Get the Repository's credentials.
     */
    public function getRepositoryCredentials()
    {
        // Check for repository credentials
        $repository            = $this->credentials->getCurrentRepository();
        $repositoryCredentials = $repository->toArray();

        // If we didn't specify a login/password ask for both the first time
        $rules = $this->rules['repository'];
        if ($repository->needsCredentials()) {
            // Else assume the repository is passwordless and only ask again for username
            $rules += ['username' => true, 'password' => true];
        }

        // Gather credentials
        $credentials = $this->gatherCredentials($rules, $repositoryCredentials, 'repository');

        // Save them to local storage and runtime configuration
        $this->localStorage->set('credentials', $credentials);
        foreach ($credentials as $key => $credential) {
            $this->config->set('scm.'.$key, $credential);
        }
    }

    /**
     * Get the LocalStorage's credentials.
     */
    public function getServerCredentials()
    {
        if ($connections = $this->command->option('on')) {
            $this->connections->setConnections($connections);
        }

        // Check for configured connections
        $availableConnections = $this->connections->getAvailableConnections();
        $activeConnections    = $this->connections->getConnections();

        // If we didn't set any connection, ask for them
        if (!$activeConnections || empty($availableConnections)) {
            $connectionName = $this->command->askWith('No connections have been set, please create one:', 'production');
            $this->getConnectionCredentials($connectionName);

            return;
        }

        // Else loop through the connections and fill in credentials
        foreach ($activeConnections as $connectionName) {
            $servers = Arr::get($availableConnections, $connectionName.'.servers');
            $servers = array_keys($servers);
            foreach ($servers as $server) {
                $this->getConnectionCredentials($connectionName, $server);
            }
        }
    }

    /**
     * Verifies and stores credentials for the given connection name.
     *
     * @param string   $connectionName
     * @param int|null $server
     */
    protected function getConnectionCredentials($connectionName, $server = null)
    {
        // Get the available connections
        $connections = $this->connections->getAvailableConnections();

        // Get the credentials for the asked connection
        $connection = $connectionName.'.servers';
        $connection = $server !== null ? $connection.'.'.$server : $connection;
        $connection = Arr::get($connections, $connection, []);

        // Update connection name
        $handle = $this->credentials->createConnectionKey($connectionName, $server);

        // Gather credentials
        $credentials = $this->gatherCredentials($this->rules['server'], $connection, $handle);

        // Save credentials
        $this->credentials->syncConnectionCredentials($handle, $credentials);
        $this->connections->setConnection($handle);
    }

    //////////////////////////////////////////////////////////////////////
    ////////////////////////////// HELPERS ///////////////////////////////
    //////////////////////////////////////////////////////////////////////

    /**
     * Whether SSH is used to connect to a server, or password.
     *
     * @param string $handle
     * @param array  $credentials
     *
     * @return bool
     */
    protected function usesSsh($handle, array $credentials)
    {
        $password = $this->getCredential($credentials, 'password');
        $key      = $this->getCredential($credentials, 'key');
        $agent    = $this->getCredential($credentials, 'agent');
        if ($password || $key || $agent) {
            return (bool) $key;
        }

        $types = ['key', 'password', 'agent'];
        $type  = $this->command->askWith('No password or SSH key is set for ['.$handle.'], which would you use?', 'key', $types);

        return $type === 'key';
    }

    /**
     * Loop through credentials and store the missing ones.
     *
     * @param boolean[] $rules
     * @param string[]  $current
     * @param string    $handle
     *
     * @return string[]
     */
    protected function gatherCredentials($rules, $current, $handle)
    {
        // Alter rules depending on connection type
        $authCredentials = ['key', 'password', 'keyphrase', 'agent', 'agent-forward'];
        $unprompted      = $this->alterRules($rules, $current, $handle);

        // Loop through credentials and ask missing ones
        foreach ($rules as $type => $required) {
            $credential   = $this->getCredential($current, $type);
            $shouldPrompt = $this->shouldPromptFor($credential);
            $shouldPrompt = !in_array($type, $unprompted, true) && ($shouldPrompt || ($required && !$credential && $credential !== false));
            $$type        = $credential;

            if ($shouldPrompt) {
                $method = in_array($type, $authCredentials, true) ? 'gatherAuthCredential' : 'gatherCredential';
                $$type  = $this->$method($handle, $type);
            }
        }

        // Reform array
        $credentials = compact(array_keys($rules));

        return $credentials;
    }

    /**
     * Gather an auth-related credential.
     *
     * @param string           $handle
     * @param string|bool|null $type
     *
     * @return string|null
     */
    protected function gatherAuthCredential($handle, $type)
    {
        $keyPath = $this->paths->getDefaultKeyPath();

        switch ($type) {
            case 'keyphrase':
                return $this->gatherCredential($handle, 'keyphrase', 'If a keyphrase is required, provide it');

            case 'key':
                return $this->command->option('key') ?: $this->command->askWith('Please enter the full path to your key', $keyPath);

            case 'password':
                return $this->gatherCredential($handle, 'password');
        }
    }

    /**
     * Look for a credential in the flags or ask for it.
     *
     * @param string      $handle
     * @param string      $type
     * @param string|null $question
     *
     * @return string|null
     */
    protected function gatherCredential($handle, $type, $question = null)
    {
        $question = $question ?: 'No '.$type.' is set for ['.$handle.'], please provide one:';
        $option   = $this->getOption($type, true);
        $method   = in_array($type, ['password', 'keyphrase'], true) ? 'askSecretly' : 'askWith';

        return $option ?: $this->command->$method($question);
    }

    /**
     * Check if a credential needs to be filled.
     *
     * @param string[] $credentials
     * @param string   $credential
     *
     * @return string|null
     */
    protected function getCredential($credentials, $credential)
    {
        $value = Arr::get($credentials, $credential);
        if (substr($value, 0, 1) === '{') {
            return;
        }

        return $value !== null ? $value : $this->command->option($credential);
    }

    /**
     * Whether Rocketeer should prompt for a credential or not.
     *
     * @param string|bool|null $value
     *
     * @return bool
     */
    protected function shouldPromptFor($value)
    {
        if (is_string($value)) {
            return !$value;
        } elseif (is_bool($value) || $value === null) {
            return (bool) $value;
        }

        return false;
    }

    /**
     * @param array  $rules
     * @param array  $credentials
     * @param string $handle
     *
     * @return string[]
     */
    protected function alterRules(array &$rules, array $credentials, $handle)
    {
        // Cancel if repository rules
        if (array_key_exists('repository', $rules)) {
            return [];
        }

        if (isset($credentials['agent']) && $credentials['agent']) {
            return ['agent', 'agent-forward'];
        } elseif ($this->usesSsh($handle, $credentials)) {
            $rules['key']       = true;
            $rules['keyphrase'] = true;

            return ['password'];
        } else {
            $rules['password'] = true;

            return ['key', 'keyphrase'];
        }
    }
}
