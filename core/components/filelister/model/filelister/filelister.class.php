<?php
/**
 * @package FileLister
 */
class FileLister {
    /**
     * FileLister constructor
     *
     * @param modX &$modx A reference to the modX instance.
     * @param array $config An array of configuration options. Optional.
     */
    function __construct(modX &$modx,array $config = array()) {
        $this->modx =& $modx;

        /* allows you to set paths in different environments
         * this allows for easier SVN management of files
         */
        $corePath = $this->modx->getOption('filelister.core_path',null,MODX_CORE_PATH.'components/filelister/');
        $assetsPath = $this->modx->getOption('filelister.assets_path',null,MODX_ASSETS_PATH.'components/filelister/');
        $assetsUrl = $this->modx->getOption('filelister.assets_url',null,MODX_ASSETS_URL.'components/filelister/');

        /* loads some default paths for easier management */
        $this->config = array_merge(array(
            'corePath' => $corePath,
            'modelPath' => $corePath.'model/',
            'chunksPath' => $corePath.'elements/chunks/',
            'snippetsPath' => $corePath.'elements/snippets/',
            'controllersPath' => $corePath.'controllers/',

            'assetsPath' => $assetsPath,
            'assetsUrl' => $assetsUrl,
            'cssUrl' => $assetsUrl.'css/',
            'jsUrl' => $assetsUrl.'js/',

            'salt' => $this->modx->getOption('filelister.salt',null,'In dreams begins responsibility.'),
        ),$config);

        $this->modx->addPackage('filelister',$this->config['modelPath']);
        $this->modx->lexicon->load('filelister:default');
    }

    /**
     * Gets a Chunk and caches it; also falls back to file-based templates
     * for easier debugging.
     *
     * @access public
     * @param string $name The name of the Chunk
     * @param array $properties The properties for the Chunk
     * @return string The processed content of the Chunk
     */
    public function getChunk($name,$properties = array(),$suffix = '.chunk.tpl') {
        $chunk = null;
        if (!isset($this->chunks[$name])) {
            $chunk = $this->modx->getObject('modChunk',array('name' => $name),true);
            if (empty($chunk)) {
                $chunk = $this->_getTplChunk($name,$suffix);
                if ($chunk == false) return false;
            }
            $this->chunks[$name] = $chunk->getContent();
        } else {
            $o = $this->chunks[$name];
            $chunk = $this->modx->newObject('modChunk');
            $chunk->setContent($o);
        }
        $chunk->setCacheable(false);
        return $chunk->process($properties);
    }
    /**
     * Returns a modChunk object from a template file.
     *
     * @access private
     * @param string $name The name of the Chunk. Will parse to name.chunk.tpl
     * @return modChunk/boolean Returns the modChunk object if found, otherwise
     * false.
     */
    private function _getTplChunk($name,$suffix = '.chunk.tpl') {
        $chunk = false;
        $f = $this->config['chunksPath'].strtolower($name).$suffix;
        if (file_exists($f)) {
            $o = file_get_contents($f);
            $chunk = $this->modx->newObject('modChunk');
            $chunk->set('name',$name);
            $chunk->setContent($o);
        }
        return $chunk;
    }

    /**
     * Ensures no hacks or relative paths are passed into a path string
     *
     * @param string $path The path to sanitize
     * @return string The sanitized path
     */
    public function sanitize($path) {
        $path = $this->modx->sanitize($path);
        $path = $this->modx->stripTags($path);
        $path = str_replace(array('../','./'),'',$path);
        $path = str_replace('//','/',$path);
        if (!$this->modx->getOption('filelister.allow_root_paths',$scriptProperties,true)) {
            $path = ltrim($path,'/');
        }
        return $path;
    }

    public function loadHeaders($file) {
        if (empty($this->headers)) {
            if ($this->modx->loadClass('filelister.feoHeaders',$this->config['modelPath'],true,true)) {
                $this->headers = new feoHeaders($this);
            } else {
                $this->modx->log(modX::LOG_LEVEL_ERROR,'[FileLister] Could not load feoHeaders class.');
                return false;
            }
        }
        $ext = pathinfo($file,PATHINFO_EXTENSION);
        return $this->headers->output($ext);
    }

    public function makeKey($key) {
        return $this->_encrypt($key);
    }
    public function parseKey($key) {
        $key = $this->_decrypt($key);
        return $this->sanitize($key);
    }

    private function _encrypt($str) {
        $key = $this->config['salt'].session_id();

        srand((double)microtime() * 1000000); /* for MCRYPT_RAND */
        $key = md5($key); /* to improve variance */

        /* open module, create IV */
        $td = mcrypt_module_open('des','','cfb','');
        $key = substr($key,0,mcrypt_enc_get_key_size($td));
        $iv_size = mcrypt_enc_get_iv_size($td);
        $iv = mcrypt_create_iv($iv_size,MCRYPT_RAND);

        /* initialize encryption handle */
        if (mcrypt_generic_init($td,$key,$iv) != -1) {
            /* Encrypt data */
            $c_t = mcrypt_generic($td,$str);
            mcrypt_generic_deinit($td);
            mcrypt_module_close($td);
            $c_t = $iv.$c_t;
            return urlencode($c_t);
        }
    }

    private function _decrypt($str) {
        $str = urldecode($str);
        $key = $this->config['salt'].session_id();

        $key = md5($key);

        /* open module, create IV */
        $td = mcrypt_module_open('des','','cfb','');
        $key = substr($key,0,mcrypt_enc_get_key_size($td));
        $iv_size = mcrypt_enc_get_iv_size($td);
        $iv = substr($str,0,$iv_size);
        $str = substr($str,$iv_size);

        /* initialize encryption handle */
        if (mcrypt_generic_init($td,$key,$iv) != -1) {
            /* decrypt data */
            $c_t = mdecrypt_generic($td,$str);
            mcrypt_generic_deinit($td);
            mcrypt_module_close($td);
            return $c_t;
        }
    }


    public function formatBytes($bytes, $precision = 2) {
        $units = array('b', 'kb', 'mb', 'gb', 'tb');

        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= pow(1024, $pow);

        return round($bytes, $precision) . ' ' . $units[$pow];
    }
}