<?php
/**
 * Reads the public realm.conf published by mod-realm-config
 * (https://github.com/Hisha/mod-realm-config) so the site can hand it to
 * Portalkeeper players and reuse its realm address and name.
 **/

class realm_config
{
    const PORTALKEEPER_URL = 'https://github.com/Hisha/Portalkeeper/releases/latest';

    private static $loaded = false;
    private static $sections = null;

    /**
     * Parsed realm.conf as [section => [key => value]], or null when no usable file is published.
     */
    public static function get()
    {
        if (self::$loaded) {
            return self::$sections;
        }
        self::$loaded = true;

        $file = get_config('realm_conf_file');
        if (empty($file) || !is_file($file) || !is_readable($file)) {
            return null;
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return null;
        }

        // The module writes a plain INI: "# comment", "[Section]" and "Key=Value" lines.
        $sections = [];
        $current = null;
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || $line[0] === ';') {
                continue;
            }
            if (preg_match('/^\[(.+)\]$/', $line, $match)) {
                $current = $match[1];
                $sections[$current] = [];
                continue;
            }
            $pos = strpos($line, '=');
            if ($current !== null && $pos !== false) {
                $sections[$current][trim(substr($line, 0, $pos))] = trim(substr($line, $pos + 1));
            }
        }

        if (empty($sections['Realm']['Name'])) {
            return null;
        }
        self::$sections = $sections;
        return $sections;
    }

    public static function value($section, $key)
    {
        $sections = self::get();
        return $sections[$section][$key] ?? '';
    }

    /**
     * Addons ('Addon') or patches ('Patch') listed in realm.conf, as [['name' => ..., 'requirement' => ...], ...].
     */
    public static function components($type)
    {
        $list = [];
        foreach (self::get() ?? [] as $section => $fields) {
            if (strpos($section, $type . '.') === 0) {
                $list[] = [
                    'name' => $fields['Name'] ?? substr($section, strlen($type) + 1),
                    'requirement' => $fields['Requirement'] ?? '',
                ];
            }
        }
        return $list;
    }

    /**
     * Portalkeeper only picks up files named *.realm.conf.
     */
    public static function download_name()
    {
        $slug = trim(preg_replace('/[^A-Za-z0-9]+/', '-', self::value('Realm', 'Name')), '-');
        return ($slug !== '' ? $slug : 'realm') . '.realm.conf';
    }

    /**
     * "Play with Portalkeeper" instructions for the How to connect section, or '' when realm.conf isn't published.
     */
    public static function render()
    {
        if (self::get() === null || empty(get_config('realm_conf_url'))) {
            return '';
        }

        $e = static fn($text) => htmlspecialchars((string)$text, ENT_QUOTES);
        $t = static fn($key, $fallback) => lang($key) ?: $fallback;

        $file_name = self::download_name();
        $min_version = self::value('Portalkeeper', 'MinimumVersion');

        $html = '<div class="portalkeeper" style="line-height: 1.5; margin-top: 20px; text-align: left;">';
        $html .= '<h4>' . $e($t('portalkeeper_title', 'Play with Portalkeeper')) . '</h4>';
        $html .= '<p>' . $e($t('portalkeeper_intro', 'Portalkeeper is a launcher that connects your game to this realm and installs the addons and patches it needs.')) . '</p>';
        $html .= '<ol>';
        $html .= '<li>' . $e($t('portalkeeper_step1', 'Download and install')) . ' <a href="' . self::PORTALKEEPER_URL . '" target="_blank" rel="noopener">Portalkeeper</a>';
        if ($min_version !== '') {
            $html .= ' (' . $e($min_version) . '+)';
        }
        $html .= '.</li>';
        $html .= '<li>' . $e($t('portalkeeper_step2', 'Download the realm file:')) . ' <a href="' . $e(get_config('realm_conf_url')) . '" download="' . $e($file_name) . '">' . $e($file_name) . '</a></li>';
        $html .= '<li>' . $e($t('portalkeeper_step3', 'Put it in your Portalkeeper realms folder:')) . ' <code>%APPDATA%\\Portalkeeper\\realms</code> (Windows) / <code>~/.config/Portalkeeper/realms</code> (Linux)</li>';
        $html .= '<li>' . $e($t('portalkeeper_step4', 'Start Portalkeeper, choose your WoW 3.3.5a folder and click ENTER REALM.')) . '</li>';
        $html .= '</ol>';

        $components = array_merge(self::components('Addon'), self::components('Patch'));
        if (!empty($components)) {
            $html .= '<p>' . $e($t('portalkeeper_components', 'Portalkeeper sets these up for you:')) . '</p><ul>';
            foreach ($components as $component) {
                $html .= '<li>' . $e($component['name']);
                if ($component['requirement'] !== '') {
                    $html .= ' (' . $e($component['requirement']) . ')';
                }
                $html .= '</li>';
            }
            $html .= '</ul>';
        }

        return $html . '</div>';
    }
}
