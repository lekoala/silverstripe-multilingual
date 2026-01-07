<?php

namespace LeKoala\Multilingual;

use Exception;
use SilverStripe\Control\Controller;
use SilverStripe\i18n\i18n;
use SilverStripe\Control\Cookie;
use SilverStripe\Control\Session;
use SilverStripe\Control\Director;
use TractorCow\Fluent\Model\Locale;
use TractorCow\Fluent\State\FluentState;
use SilverStripe\Core\Config\Configurable;

/**
 * i18n helper class
 */
class LangHelper
{
    use Configurable;

    /**
     * The default key for global translation
     */
    const GLOBAL_ENTITY = 'Global';

    /**
     * Provision fluent locales defined in yml
     * Use LangHelper::provisionLocales
     *
     * eg:
     * LeKoala\Multilingual\LangHelper:
     *   default_locales:
     *     - en_US
     *     - fr_FR
     *
     * @var array<string>
     */
    private static $default_locales = [];

    /**
     * @config
     * @var boolean
     */
    private static $persist_cookie = true;

    /**
     * @var array<string,string>
     */
    protected static $locale_cache = [];

    /**
     * Get a global translation
     *
     * By default all global translation are stored under the Global key
     *
     * @param string $entity If no entity is specified, Global is assumed
     * @return string
     */
    public static function globalTranslation(string $entity): string
    {
        $parts = explode('.', $entity);
        if (count($parts) == 1) {
            array_unshift($parts, self::GLOBAL_ENTITY);
        }
        return i18n::_t(implode('.', $parts), $entity);
    }

    public static function hasFluent(): bool
    {
        return class_exists('\\TractorCow\\Fluent\\Middleware\\DetectLocaleMiddleware');
    }

    /**
     * Call this to make sure we are not setting any cookies that has
     * not been accepted
     */
    public static function persistLocaleIfCookiesAreAllowed(): void
    {
        if (headers_sent()) {
            return;
        }

        if (!self::hasFluent()) {
            return;
        }

        $persist = static::config()->persist_cookie;
        if (!$persist) {
            return;
        }

        // If we choose to persist cookies, we should check our cookie consent first
        $dont_persist = $persist;
        // cookie consent from osano
        $status = Cookie::get('cookieconsent_status') ?? '';
        if (strlen($status) && $status == 'allow') {
            $dont_persist = false;
        }
        // cookie from cookieconsent
        $status = Cookie::get('cookie_consent_user_accepted') ?? '';
        if (strlen($status) && $status == 'true') {
            $dont_persist = false;
        }

        if ($dont_persist) {
            return;
        }

        self::persistLocale();
    }

    public static function getLanguageName($locale, $short = true)
    {
        $locale = self::get_locale_from_lang($locale);

        $allLocales = i18n::getSources()->getKnownLocales();

        $foundLocale = $allLocales[$locale] ?? null;
        if ($foundLocale && $short) {
            $foundLocale = preg_replace('/\s*\(.*?\)/', '', $foundLocale);
            $foundLocale = ucfirst(trim($foundLocale));
        }
        return $foundLocale;
    }

    /**
     * Persist locale according to fluent settings
     */
    public static function persistLocale(): void
    {
        if (!self::hasFluent()) {
            return;
        }

        $curr = Controller::curr();
        $request = $curr->getRequest();

        $secure = Director::is_https($request)
            && Session::config()->get('cookie_secure');

        $class = \TractorCow\Fluent\Middleware\DetectLocaleMiddleware::class;
        $persistIds = $class::config()->get('persist_ids');
        $persistKey = FluentState::singleton()->getIsFrontend()
            ? $persistIds['frontend']
            : $persistIds['cms'];

        $locale = $request->getSession()->get($persistKey);
        // If session is not started or set, it may not be set
        if (!$locale) {
            $locale = self::get_locale();
        }

        Cookie::set(
            $persistKey,
            $locale,
            $class::config()->get('persist_cookie_expiry'),
            $class::config()->get('persist_cookie_path'),
            $class::config()->get('persist_cookie_domain'),
            $secure,
            $class::config()->get('persist_cookie_http_only')
        );
    }

    /**
     * Provision locales defined in default_locales
     *
     * @return void
     */
    public static function provisionLocales()
    {
        $locales = self::config()->default_locales;
        if (empty($locales)) {
            throw new Exception("No locales defined in LangHelper:default_locales");
        }

        foreach ($locales as $loc) {
            $Locale = Locale::get()->filter('Locale', $loc)->first();
            $allLocales = i18n::getData()->getLocales();
            if (!$Locale) {
                $Locale = new Locale();
                $Locale->Title = $allLocales[$loc];
                $Locale->Locale = $loc;
                $Locale->URLSegment = self::get_lang($loc);
                $Locale->IsGlobalDefault = $loc == i18n::get_locale();
                $Locale->write();
            }
        }
    }

    /**
     * Make sure we get a proper two characters lang
     *
     * @param string|object $lang a string or a fluent locale object
     * @return string a two chars lang
     */
    public static function get_lang($lang = null)
    {
        if (!$lang) {
            $lang = self::get_locale();
        }
        if (is_object($lang)) {
            $lang = $lang->Locale;
        }
        return substr($lang, 0, 2);
    }

    /**
     * Get the right locale (using fluent data if exists)
     *
     * @return string
     */
    public static function get_locale()
    {
        if (class_exists(FluentState::class)) {
            $locale = FluentState::singleton()->getLocale();
            // Locale may not be set, in tests for instance
            if ($locale) {
                return $locale;
            }
        }
        return i18n::get_locale();
    }

    /**
     * Get a locale from the lang
     *
     * @param string $lang
     * @return string
     */
    public static function get_locale_from_lang($lang)
    {
        // Use cache
        if (isset(self::$locale_cache[$lang])) {
            return self::$locale_cache[$lang];
        }
        // Use fluent data
        if (class_exists(Locale::class)) {
            if (empty(self::$locale_cache)) {
                $fluentLocales = Locale::getLocales();
                foreach ($fluentLocales as $locale) {
                    self::$locale_cache[self::get_lang($locale->Locale)] = $locale->Locale;
                }
            }
        }
        // Use i18n data
        if (!isset(self::$locale_cache[$lang])) {
            $localesData = i18n::getData();
            self::$locale_cache[$lang] = $localesData->localeFromLang($lang);
        }
        // Return cached value
        return self::$locale_cache[$lang];
    }

    /**
     * Do we have the subsite module installed
     * TODO: check if it might be better to use module manifest instead?
     *
     * @return bool
     */
    public static function usesFluent()
    {
        return class_exists(FluentState::class);
    }

    /**
     * @return array<string>
     */
    public static function get_available_langs()
    {
        if (!self::usesFluent()) {
            return [
                self::get_lang()
            ];
        }

        $allLocales = Locale::get();
        $results = [];
        foreach ($allLocales as $locale) {
            $results[] = $locale->URLSegment;
        }
        return $results;
    }

    /**
     * Execute the callback in given locale
     *
     * @param string $locale
     * @param callable $cb
     * @return mixed the callback result
     */
    public static function withLocale($locale, $cb)
    {
        if (!self::usesFluent() || !$locale) {
            $cb();
            return;
        }
        if (!is_string($locale)) {
            $locale = $locale->Locale;
        }
        $state = FluentState::singleton();
        // Execute callback while setting fluent/i18n state
        // withState will restore previous i18n locale
        $result = $state->withState(
            function (FluentState $state) use ($locale, $cb) {
                $state->setLocale($locale);
                i18n::set_locale($locale);
                return $cb();
            }
        );
        return $result;
    }

    /**
     * Execute the callback for all locales
     *
     * @param callable $cb
     * @return array an array of callback results
     */
    public static function withLocales($cb)
    {
        if (!self::usesFluent()) {
            $cb();
            return [];
        }
        $allLocales = Locale::get();
        $results = [];
        foreach ($allLocales as $locale) {
            $results[] = self::withLocale($locale, $cb);
        }
        return $results;
    }

    /**
     * Convert a locale title to a simple lang name
     * @param string $locale
     * @return string
     */
    public static function localeToLang(string $locale): string
    {
        // Remove parenthesis
        $cleanedLocale = preg_replace('/\s*\(.*?\)/', '', $locale);
        // First letter should be uppercased for consistency
        $cleanedLocale = mb_ucfirst(trim($cleanedLocale));
        return $cleanedLocale;
    }

    /**
     * Get all fluent locales
     * @return array
     */
    public static function getApplicationLanguages(): array
    {
        if (!self::usesFluent()) {
            $title = i18n::getData()->languageName(i18n::get_locale());
            return [
                i18n::get_locale() => self::localeToLang($title)
            ];
        }
        $locales = Locale::get();
        $arr = [];
        foreach ($locales as $loc) {
            $arr[$loc->Locale] = self::localeToLang($loc->getTitle());
        }
        return $arr;
    }
}
