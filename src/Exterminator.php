<?php

namespace WebTheory\Exterminate;

use Monolog\Formatter\LineFormatter;
use Monolog\Handler\ErrorLogHandler;
use Monolog\Logger;
use Monolog\Processor\GitProcessor;
use Monolog\Processor\PsrLogMessageProcessor;
use NunoMaduro\Collision\Handler;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\DataCollector\DumpDataCollector;
use Symfony\Component\HttpKernel\Debug\FileLinkFormatter;
use Symfony\Component\VarDumper\Caster\ReflectionCaster;
use Symfony\Component\VarDumper\Cloner\VarCloner;
use Symfony\Component\VarDumper\Dumper\CliDumper;
use Symfony\Component\VarDumper\Dumper\ContextProvider\CliContextProvider;
use Symfony\Component\VarDumper\Dumper\ContextProvider\RequestContextProvider;
use Symfony\Component\VarDumper\Dumper\ContextProvider\SourceContextProvider;
use Symfony\Component\VarDumper\Dumper\ContextualizedDumper;
use Symfony\Component\VarDumper\Dumper\HtmlDumper;
use Symfony\Component\VarDumper\Dumper\ServerDumper;
use Symfony\Component\VarDumper\VarDumper;
use Whoops\Handler\PlainTextHandler;
use Whoops\Handler\PrettyPageHandler;
use Whoops\Run;
use Whoops\RunInterface;
use Whoops\Util\Misc;

class Exterminator
{
    public const DEFAULT_EDITOR = 'phpstorm';

    public const EDITOR_FORMATS = [
        'atom' => 'atom://core/open/file?filename=%f&line=%l',
        'emacs' => 'emacs://open?url=file://%f&line=%l',
        'macvim' => 'mvim://open?url=file://%f&line=%l',
        'phpstorm' => 'phpstorm://open?file=%f&line=%l',
        'sublime' => 'subl://open?url=file://%f&line=%l',
        'textmate' => 'txmt://open?url=file://%f&line=%l',
        'vscode' => 'vscode://file/%f:%l',
    ];

    public static function resolveFormat(?string $editor = null, ?string $format = null)
    {
        $default = ini_get('xdebug.file_link_format')
            ?: get_cfg_var('xdebug.file_link_format');

        return $format ?? ($editor ? static::EDITOR_FORMATS[$editor] : $default);
    }

    public static function init(array $options)
    {
        $enable = $options['enable'] ?? true;
        $editor = $options['editor'] ?? static::DEFAULT_EDITOR;
        $format = $options['format'] ?? null;
        $logFile = $options['log'] ?? null;

        $format = static::resolveFormat($editor, $format);

        static::basic($enable, $logFile, $format);

        $loggerOptions = $options['error_logger'] ?? [];
        $errorOptions = $options['error_handler'] ?? [];
        $dumperOptions = $options['var_dumper'] ?? [];
        $iniOptions = $options['ini'] ?? [];
        $xdebugOptions = $options['xdebug'] ?? [];

        if ($iniOptions) {
            static::ini($iniOptions);
        }

        if ($xdebugOptions) {
            static::xdebug($xdebugOptions);
        }

        if ($loggerOptions) {
            $logger = static::errorLogger($loggerOptions['channel'] ?? 'errorlog');
        }

        if ($errorOptions) {
            static::errorHandler(
                $logger ?? $errorOptions['logger'] ?? null,
                $format ?? $errorOptions['link_format'] ?? null,
                $errorOptions['host_os'] ?? null,
                $errorOptions['host_path'] ?? null,
                $errorOptions['guest_path'] ?? null,
                $enable
            );
        }

        if ($dumperOptions) {
            static::varDumper(
                $dumperOptions['root'],
                $dumperOptions['theme'] ?? 'dark',
                $dumperOptions['max_items'] ?? -1,
                $dumperOptions['max_string'] ?? -1,
                $dumperOptions['min_depth'] ?? 1,
                $dumperOptions['server_host'] ?? null
            );
        }
    }

    public static function basic(bool $enable, ?string $logFile = null, ?string $linkFormat = null): void
    {
        if (!empty($logFile) && !file_exists($logPath = dirname($logFile))) {
            mkdir($logPath, 0777, true);
        }

        ini_set('error_reporting', E_ALL);
        ini_set('display_errors', $enable);

        ini_set('log_errors', true);
        ini_set('error_log', $logFile);

        ini_set('xdebug.file_link_format', $linkFormat);
    }

    /**
     * Set ini directives
     *
     * @link https://www.php.net/manual/en/errorfunc.configuration
     */
    public static function ini(array $directives = []): void
    {
        $defaults = [];

        foreach ($directives + $defaults as $option => $value) {
            ini_set($option, $value);
        }
    }

    /**
     * Define xdebug settings
     *
     * @link https://xdebug.org/docs/all_settings
     */
    public static function xdebug(array $settings = []): void
    {
        $defaults = [];

        foreach ($settings + $defaults as $setting => $value) {
            ini_set("xdebug.{$setting}", $value);
        }
    }

    /**
     * Monolog logging
     *
     * @link https://seldaek.github.io/monolog
     */
    public static function errorLogger($channel = 'errorlog'): Logger
    {
        $logger = new Logger($channel);
        $handler = new ErrorLogHandler();
        $formatter = new LineFormatter();

        $formatter->allowInlineLineBreaks(true);
        $formatter->ignoreEmptyContextAndExtra(true);

        $handler->setFormatter($formatter);

        $logger
            ->pushHandler($handler)
            ->pushProcessor(new PsrLogMessageProcessor())
            ->pushProcessor(new GitProcessor());

        return $logger;
    }

    /**
     * Whoops error handling
     *
     * @link http://filp.github.io/whoops
     * @link https://github.com/nunomaduro/collision
     */
    public static function errorHandler(
        ?LoggerInterface $logger = null,
        ?string $linkFormat = null,
        ?string $hostOs = null,
        ?string $hostPath = null,
        ?string $guestPath = null,
        bool $display = true
    ): Run {
        if (Misc::isCommandLine()) {
            $outputHandler = new Handler();
            $outputHandler->getWriter()
                ->getOutput()
                ->setVerbosity(OutputInterface::VERBOSITY_DEBUG);
        } else {
            $outputHandler = new PrettyPageHandler();
            if ($hostOs) {
                $outputHandler->setEditor(function ($file, $line) use (
                    $linkFormat,
                    $hostOs,
                    $hostPath,
                    $guestPath
                ) {
                    $file = str_replace($guestPath, $hostPath, $file);

                    if ($hostOs == 'WINDOWS') {
                        $file = str_replace('/', '\\', $file);
                    }

                    return str_replace(['%f', '%l'], [$file, $line], $linkFormat);
                });
            }
        }

        $logHandler = new PlainTextHandler($logger);
        $logHandler
            ->setDumper('dump')
            ->loggerOnly(true)
            ->addTraceToOutput(true)
            ->addPreviousToOutput(true);

        $run = new Run();
        $run->writeToOutput($display);
        $run->pushHandler($outputHandler)
            ->pushHandler($logHandler)
            ->register();

        if ($logger instanceof Logger) {
            $logger->setExceptionHandler([$run, RunInterface::EXCEPTION_HANDLER]);
        }

        return $run;
    }

    /**
     * Symfony VarDumper
     *
     * @link https://symfony.com/doc/current/components/var_dumper
     * @link https://symfony.com/doc/current/components/var_dumper/advanced
     */
    public static function varDumper(
        string $root,
        string $theme = 'dark',
        int $maxItems = -1,
        int $maxString = -1,
        int $minDepth = 1,
        ?string $serverHost = null
    ) {
        $serverHost = $serverHost ?? 'tcp://127.0.0.1:9912';

        $htmlDumper = new HtmlDumper();
        $cliDumper = new CliDumper();
        $requestStack = new RequestStack();
        $linkFormatter = new FileLinkFormatter();
        $cloner = new VarCloner();

        $htmlDumper->setTheme($theme);
        $requestStack->push(Request::createFromGlobals());

        $contextProviders = [
            'cli' => new CliContextProvider(),
            'source' => new SourceContextProvider('UTF-8', $root, $linkFormatter),
            'request' => new RequestContextProvider($requestStack)
        ];

        $fallbackDumper = in_array(PHP_SAPI, ['cli', 'phpdbg']) ? $cliDumper : $htmlDumper;
        $fallbackDumper = new DumpDataCollector(null, $linkFormatter, null, $requestStack, $fallbackDumper); // displays file and line in output
        $fallbackDumper = new ContextualizedDumper($fallbackDumper, $contextProviders); // adds context caret to output

        $dumper = new ServerDumper($serverHost, $fallbackDumper, $contextProviders);

        $cloner->addCasters(ReflectionCaster::UNSET_CLOSURE_FILE_INFO);
        $cloner->setMaxItems($maxItems);
        $cloner->setMinDepth($minDepth);
        $cloner->setMaxString($maxString);

        VarDumper::setHandler(function ($var) use ($dumper, $cloner) {
            $dumper->dump($cloner->cloneVar($var));
        });
    }
}
