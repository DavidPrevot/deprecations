<?php

namespace Doctrine\Deprecations;

use Psr\Log\LoggerInterface;

/**
 * Manages Deprecation logging in different ways.
 *
 * By default triggered exceptions are not logged, only the amount of
 * depreceations triggered can be queried with `Deprecation::getUniqueTriggeredDeprecationsCount()`.
 *
 * To enable different deprecation logging mechanisms you can call the
 * following methods:
 *
 *  - Uses trigger_error with E_USER_DEPRECATED
 *    \Doctrine\Deprecations\Deprecation::enableWithTriggerError();
 *
 *  - Uses @trigger_error with E_USER_DEPRECATED
 *    \Doctrine\Deprecations\Deprecation::enableWithSuppressedTriggerError();
 *
 *  - Sends deprecation messages via a PSR-3 logger
 *    \Doctrine\Deprecations\Deprecation::enableWithPsrLogger($logger);
 *
 * Packages that trigger deprecations should use the `trigger()` method.
 */
class Deprecation
{
    private const TYPE_NONE = 0;
    private const TYPE_TRIGGER_ERROR = 1;
    private const TYPE_TRIGGER_SUPPRESSED_ERROR = 2;
    private const TYPE_PSR_LOGGER = 3;

    /** @var int */
    private static $type = self::TYPE_NONE;

    /** @var \Psr\Logger\LoggerInterface */
    private static $logger;

    /** @var array<string,bool> */
    private static $ignoredPackages = [];

    /** @var array<string,int> */
    private static $ignoredLinks = [];

    /**
     * Trigger a deprecation for the given package, starting with given version.
     *
     * The link should point to a Github issue or Wiki entry detailing the
     * deprecation. It is additionally used to de-duplicate the trigger of the
     * same deprecation during a request.
     */
    public static function trigger(string $package, string $version, string $link, string $message, ...$args) : void
    {
        if (array_key_exists($link, self::$ignoredLinks)) {
            self::$ignoredLinks[$link]++;
            return;
        }

        // ignore this deprecation until the end of the request now
        self::$ignoredLinks[$link] = 1;

        if (self::$type === self::TYPE_NONE) {
            return;
        }

        if (isset(self::$ignoredPackages[$package])) {
            return;
        }

        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1);

        $message = sprintf($message, ...$args);

        if (self::$type === self::TYPE_TRIGGER_ERROR) {
            $message .= sprintf(
                " (%s:%s, %s, since %s %s)",
                basename($backtrace[0]['file']),
                $backtrace[0]['line'],
                $link,
                $package,
                $version
            );

            trigger_error($message, E_USER_DEPRECATED);
        } elseif (self::$type === self::TYPE_TRIGGER_SUPPRESSED_ERROR) {
            $message .= sprintf(
                " (%s:%s, %s, since %s %s)",
                basename($backtrace[0]['file']),
                $backtrace[0]['line'],
                $link,
                $package,
                $version
            );

            @trigger_error($message, E_USER_DEPRECATED);
        } elseif (self::$type === self::TYPE_PSR_LOGGER) {
            $context = [
                'file' => $backtrace[0]['file'],
                'line' => $backtrace[0]['line'],
            ];

            $context['package'] = $package;
            $context['since'] = $version;
            $context['link'] = $link;

            self::$logger->debug($message, $context);
        }
    }

    public static function enableWithTriggerError()
    {
        self::$type = self::TYPE_TRIGGER_ERROR;
    }

    public static function enableWithSuppressedTriggerError()
    {
        self::$type = self::TYPE_TRIGGER_SUPPRESSED_ERROR;
    }

    public static function enableWithPsrLogger(LoggerInterface $logger)
    {
        self::$type = self::TYPE_PSR_LOGGER;
        self::$logger = $logger;
    }

    public static function disable()
    {
        self::$type = self::TYPE_NONE;
        self::$logger = null;
    }

    public static function ignorePackages(...$packages) : void
    {
        foreach ($packages as $package) {
            self::$ignoredPackages[$package] = true;
        }
    }

    public static function ignoreDeprecations(...$links) : void
    {
        foreach ($links as $link) {
            self::$ignoredLinks[$link] = 0;
        }
    }

    public static function getUniqueTriggeredDeprecationsCount() : int
    {
        return count(self::$ignoredLinks);
    }

    /**
     * Returns each triggered deprecation link identifier and the amount of occurrences.
     *
     * @return array<string,int>
     */
    public static function getTriggeredDeprecations() : array
    {
        return self::$ignoredLinks;
    }
}
