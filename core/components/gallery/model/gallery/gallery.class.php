<?php
/**
 * @package gallery
 */
/**
 * The base class for Gallery.
 *
 * @package gallery
 */
class Gallery {
    public $debugTimer;
    function __construct(modX &$modx,array $config = array()) {
        $this->modx =& $modx;

        $corePath = $this->modx->getOption('gallery.core_path',$config,$this->modx->getOption('core_path').'components/discuss/');
        $assetsPath = $this->modx->getOption('gallery.assets_path',$config,$this->modx->getOption('assets_path').'components/discuss/');
        $assetsUrl = $this->modx->getOption('gallery.assets_url',$config,$this->modx->getOption('assets_url').'components/discuss/');

        $connectorId = $this->modx->getOption('gallery.connector_resource',$config,1);
        $connectorUrl = $this->modx->makeUrl($connectorId);

        $this->config = array_merge(array(
            'assetsUrl' => $assetsUrl,
            'cssUrl' => $assetsUrl.'css/',
            'jsUrl' => $assetsUrl.'js/',
            'imagesUrl' => $assetsUrl.'images/',

            'connectorUrl' => $connectorUrl,

            'corePath' => $corePath,
            'modelPath' => $corePath.'model/',
            'chunksPath' => $corePath.'elements/chunks/',
            'pagesPath' => $corePath.'elements/pages/',
            'snippetsPath' => $corePath.'elements/snippets/',
            'processorsPath' => $corePath.'processors/',
            'hooksPath' => $corePath.'hooks/',
            'useCss' => true,
            'loadJQuery' => true,
        ),$config);

        if (!file_exists($this->modx->getOption('core_path').'cache/phpthumb/')) {
            @mkdir($this->modx->getOption('core_path').'cache/phpthumb/',0755);
        }

        $this->modx->addPackage('gallery',$this->config['modelPath'],'gallery_');
        if ($this->modx->getOption('gallery.debug',$this->config,true)) {
            $this->startDebugTimer();
        }
    }

    /**
     * Initializes Gallery into different contexts.
     *
     * @access public
     * @param string $ctx The context to load. Defaults to web.
     */
    public function initialize($ctx = 'web') {

/*
        $this->modx->getService('hooks','gallery.galHooks',$this->config['modelPath'],array(
            'gallery' => &$this,
        ));
        */

        switch ($ctx) {
            case 'mgr':
                if (!$this->modx->loadClass('gallery.request.GalControllerRequest',$this->config['modelPath'],true,true)) {
                    return 'Could not load controller request handler.';
                }
                $this->request = new GalControllerRequest($this);
                return $this->request->handleRequest();
            break;
            case 'connector':

                if (!$this->modx->loadClass('gallery.request.GalConnectorRequest',$this->config['modelPath'],true,true)) {
                    return 'Could not load connector request handler.';
                }
                $this->request = new GalConnectorRequest($this);
                return $this->request->handle();
            break;
            default:

                $this->modx->lexicon->load('gallery:web');

                if ($this->modx->getOption('gallery.use_css',null,true)) {
                    $this->modx->regClientCSS($this->config['cssUrl'].'index.css');
                }
                if ($this->modx->getOption('gallery.load_jquery',null,true)) {
                    $this->modx->regClientStartupScript($this->config['jsUrl'].'web/jquery-1.3.2.min.js');
                }
                $this->modx->regClientStartupScript($this->config['jsUrl'].'web/gallery.js');
                $this->modx->regClientStartupScript('<script type="text/javascript">
    $(function() {
        GAL.config.connector = "'.$this->config['connectorUrl'].'";
        GAL.config.context = "'.$this->modx->context->get('key').'?ctx=mgr";
    });</script>
                ');
            break;
        }
    }

    public function loadProcessor($name,array $scriptProperties = array()) {
        if (!isset($this->modx->error)) $this->modx->request->loadErrorHandler();

        $path = $this->config['processorsPath'].$name.'.php';
        $processorOutput = false;
        if (file_exists($path)) {
            $modx =& $this->modx;
            $gallery =& $this;

            $processorOutput = include $path;
        } else {
            $processorOutput = $this->modx->error->failure('No action specified.');
        }
        return $processorOutput;
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
    public function getChunk($name,array $properties = array()) {
        $chunk = null;
        if (!isset($this->chunks[$name])) {
            /*$chunk = $this->modx->getObject('modChunk',array('name' => $name),true);*/
            if (empty($chunk)) {
                $chunk = $this->_getTplChunk($name);
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
    private function _getTplChunk($name) {
        $chunk = false;
        $f = $this->config['chunksPath'].strtolower($name).'.chunk.tpl';
        if (file_exists($f)) {
            $o = file_get_contents($f);
            $chunk = $this->modx->newObject('modChunk');
            $chunk->set('name',$name);
            $chunk->setContent($o);
        }
        return $chunk;
    }

    /**
     * Used for development and debugging
     */
    public function getPage($name,array $properties = array()) {
        $name = str_replace('.','/',$name);
        $f = $this->config['pagesPath'].strtolower($name).'.tpl';
        $o = '';
        if (file_exists($f)) {
            $o = file_get_contents($f);
            $chunk = $this->modx->newObject('modChunk');
            $chunk->setContent($o);
            return $chunk->process($properties);
        }
        return false;
    }


    /**
     * Output the final forum output and wrap in the disWrapper chunk, if in
     * debug mode. The wrapper code will need to be in the Template if not in
     * debug mode.
     *
     * @access public
     * @param string $output The output to process
     * @return string The final wrapped output, or blank if not in debug.
     */
    public function output($page = '',array $properties = array()) {
        if ($this->modx->getOption('gallery.debug',null,false)) {
            $output = $this->getChunk('galWrapper',array(
                'gallery.output' => $this->getPage($page,$properties),
            ));

            if ($this->debugTimer !== false) {
                $output .= "<br />\nExecution time: ".$this->endDebugTimer()."\n";
            }

            return $output;
        }

        $this->modx->toPlaceholders($properties);
        return '';
    }

    /**
     * Builds pagination
     *
     * @access public
     * @param int $count The total number of records
     * @param int $limit The # of records per page
     * @param int $start The current starting record
     * @param string $url The URL for the links to go to.
     * @return string
     */
    public function buildPagination($count,$limit,$start,$url) {
        $pageCount = $count / $limit;
        $curPage = $start / $limit;
        $pages = '';

        $params = $_GET;
        unset($params['q']);

        for ($i=0;$i<$pageCount;$i++) {
            $newStart = $i*$limit;
            $u = $url.'?'.http_build_query(array_merge($params,array(
                'start' => $newStart,
                'limit' => $limit,
            )));
            if ($i != $curPage) {
                $pages .= '<li class="gal-page-number"><a href="'.$u.'">'.($i+1).'</a></li>';
            } else {
                $pages .= '<li class="gal-page-number gal-page-current">'.($i+1).'</li>';
            }
        }
        if (empty($pages)) $pages = '<li class="gal-page-number gal-page-current">1</li>';
        return $pages;
    }

    /**
     * Sends an email based on the specified information and templates.
     *
     * @access public
     * @param string $email The email to send to.
     * @param string $name The name of the user to send to.
     * @param string $subject The subject of the email.
     * @param array $properties A collection of properties.
     * @return array
     */
    public function sendEmail($email,$name,$subject,array $properties = array()) {
        if (empty($properties['tpl'])) return false;
        if (empty($properties['tplType'])) $properties['tplType'] = 'modChunk';

        $msg = $this->getChunk($properties['tpl'],$properties,$properties['tplType']);

        $this->modx->getService('mail', 'mail.modPHPMailer');
        $this->modx->mail->set(modMail::MAIL_BODY, $msg);
        $this->modx->mail->set(modMail::MAIL_FROM, $this->modx->getOption('emailsender'));
        $this->modx->mail->set(modMail::MAIL_FROM_NAME, $this->modx->getOption('site_name'));
        $this->modx->mail->set(modMail::MAIL_SENDER, $this->modx->getOption('emailsender'));
        $this->modx->mail->set(modMail::MAIL_SUBJECT, $subject);
        $this->modx->mail->address('to', $email, $name);
        $this->modx->mail->address('reply-to', $this->modx->getOption('emailsender'));
        $this->modx->mail->setHTML(true);
        $sent = $this->modx->mail->send();
        $this->modx->mail->reset();

        return $sent;
    }

    /**
     * Processes results from the error handler
     *
     * @access public
     * @param array $result The result from the processor
     * @return boolean The success of the processor
     */
    public function processResult(array $result = array()) {
        if (!is_array($result) || !isset($result['success'])) return false;

        if ($result['success'] == false) {
            foreach ($result['errors'] as $error) {
                $this->modx->toPlaceholder($error['id'],$error['msg'],'error');
            }
        }
        if (!empty($_POST)) $this->modx->toPlaceholders($_POST,'post');
        return $result['success'];
    }

    /**
     * Starts the debug timer.
     *
     * @access protected
     * @return int The start time.
     */
    protected function startDebugTimer() {
        $mtime = microtime();
        $mtime = explode(' ', $mtime);
        $mtime = $mtime[1] + $mtime[0];
        $tstart = $mtime;
        $this->debugTimer = $tstart;
        return $this->debugTimer;
    }
    /**
     * Ends the debug timer and returns the total number of seconds Discuss took
     * to run.
     *
     * @access protected
     * @return int The end total time to execute the script.
     */
    protected function endDebugTimer() {
        $mtime= microtime();
        $mtime= explode(" ", $mtime);
        $mtime= $mtime[1] + $mtime[0];
        $tend= $mtime;
        $totalTime= ($tend - $this->debugTimer);
        $totalTime= sprintf("%2.4f s", $totalTime);
        $this->debugTimer = false;
        return $totalTime;
    }
}