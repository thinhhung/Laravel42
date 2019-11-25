<?php

namespace App\Exceptions;

use Whoops\Handler\PrettyPageHandler;
use Whoops\Handler\Handler;
use Whoops\Util\TemplateHelper;
use Whoops\Exception\Formatter;
use Config;

class WhoopsPrettyPageHandler extends PrettyPageHandler
{
    /**
     * The name of the custom css file.
     *
     * @var string
     */
    private $customCss = null;

    /**
     * @var array[]
     */
    private $blacklist = [
        '_GET' => [],
        '_POST' => [],
        '_FILES' => [],
        '_COOKIE' => [],
        '_SESSION' => [],
        '_SERVER' => [],
        '_ENV' => [],
    ];

    /**
     * Constructor.
     */
    public function __construct()
    {
        parent::__construct();
        foreach (Config::get('app.debug_blacklist', []) as $key => $secrets) {
            foreach ($secrets as $secret) {
                $this->blacklist($key, $secret);
            }
        }
    }

    /**
     * @return int|null
     */
    public function handle()
    {
        if (!$this->handleUnconditionally()) {
            // Check conditions for outputting HTML:
            // @todo: Make this more robust
            if (php_sapi_name() === 'cli') {
                // Help users who have been relying on an internal test value
                // fix their code to the proper method
                if (isset($_ENV['whoops-test'])) {
                    throw new \Exception(
                        'Use handleUnconditionally instead of whoops-test'
                        .' environment variable'
                    );
                }

                return Handler::DONE;
            }
        }

        // @todo: Make this more dynamic
        $helper = new TemplateHelper();

        $templateFile = $this->getResource("views/layout.html.php");
        $cssFile      = $this->getResource("css/whoops.base.css");
        $zeptoFile    = $this->getResource("js/zepto.min.js");
        $jsFile       = $this->getResource("js/whoops.base.js");

        if ($this->customCss) {
            $customCssFile = $this->getResource($this->customCss);
        }

        $inspector = $this->getInspector();
        $frames    = $inspector->getFrames();

        $code = $inspector->getException()->getCode();

        if ($inspector->getException() instanceof \ErrorException) {
            // ErrorExceptions wrap the php-error types within the "severity" property
            $code = Misc::translateErrorCode($inspector->getException()->getSeverity());
        }

        // List of variables that will be passed to the layout template.
        $vars = array(
            "page_title" => $this->getPageTitle(),

            // @todo: Asset compiler
            "stylesheet" => file_get_contents($cssFile),
            "zepto"      => file_get_contents($zeptoFile),
            "javascript" => file_get_contents($jsFile),

            // Template paths:
            "header"      => $this->getResource("views/header.html.php"),
            "frame_list"  => $this->getResource("views/frame_list.html.php"),
            "frame_code"  => $this->getResource("views/frame_code.html.php"),
            "env_details" => $this->getResource("views/env_details.html.php"),

            "title"          => $this->getPageTitle(),
            "name"           => explode("\\", $inspector->getExceptionName()),
            "message"        => $inspector->getException()->getMessage(),
            "code"           => $code,
            "plain_exception" => Formatter::formatExceptionPlain($inspector),
            "frames"         => $frames,
            "has_frames"     => !!count($frames),
            "handler"        => $this,
            "handlers"       => $this->getRun()->getHandlers(),

            "tables"      => array(
                "GET Data"              => $this->masked($_GET, '_GET'),
                "POST Data"             => $this->masked($_POST, '_POST'),
                "Files"                 => isset($_FILES) ? $this->masked($_FILES, '_FILES') : [],
                "Cookies"               => $this->masked($_COOKIE, '_COOKIE'),
                "Session"               => isset($_SESSION) ? $this->masked($_SESSION, '_SESSION') :  [],
                "Server/Request Data"   => $this->masked($_SERVER, '_SERVER'),
                "Environment Variables" => $this->masked($_ENV, '_ENV'),
            ),
        );

        if (isset($customCssFile)) {
            $vars["stylesheet"] .= file_get_contents($customCssFile);
        }

        // Add extra entries list of data tables:
        // @todo: Consolidate addDataTable and addDataTableCallback
        $extraTables = array_map(function ($table) {
            return $table instanceof \Closure ? $table() : $table;
        }, $this->getDataTables());
        $vars["tables"] = array_merge($extraTables, $vars["tables"]);

        $helper->setVariables($vars);
        $helper->render($templateFile);

        return Handler::QUIT;
    }

    /**
     * blacklist a sensitive value within one of the superglobal arrays.
     *
     * @param $superGlobalName string the name of the superglobal array, e.g. '_GET'
     * @param $key string the key within the superglobal
     */
    public function blacklist($superGlobalName, $key)
    {
        $this->blacklist[$superGlobalName][] = $key;
    }

    /**
     * Checks all values within the given superGlobal array.
     * Blacklisted values will be replaced by a equal length string cointaining only '*' characters.
     *
     * We intentionally dont rely on $GLOBALS as it depends on 'auto_globals_jit' php.ini setting.
     *
     * @param $superGlobal array One of the superglobal arrays
     * @param $superGlobalName string the name of the superglobal array, e.g. '_GET'
     * @return array $values without sensitive data
     */
    private function masked(array $superGlobal, $superGlobalName)
    {
        $blacklisted = $this->blacklist[$superGlobalName];
        $values = $superGlobal;
        foreach ($blacklisted as $key) {
            if (isset($superGlobal[$key]) && is_string($superGlobal[$key])) {
                $values[$key] = str_repeat('*', strlen($superGlobal[$key]));
            }
        }
        return $values;
    }
}
