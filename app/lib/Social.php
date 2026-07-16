<?php
/**
 * Social-Media-Kanäle der Wirtschaftsjunioren Forchheim.
 *
 * Zentral im Admin gepflegt (Settings-Einträge `social_<key>`), damit die Links
 * an vielen Stellen der App wiederverwendet werden können (Präsentation,
 * E-Mails, Footer …). Nur gepflegte Kanäle werden angezeigt.
 */

declare(strict_types=1);

final class Social
{
    /**
     * Bekannte Kanäle in Anzeigereihenfolge.
     * key => [Anzeigename, Symbol, Platzhalter-URL].
     * @var array<string,array{0:string,1:string,2:string}>
     */
    public const CHANNELS = [
        'website'   => ['Web',       '🌐', 'https://wj-forchheim.de'],
        'instagram' => ['Instagram', '📷', 'https://instagram.com/…'],
        'facebook'  => ['Facebook',  '📘', 'https://facebook.com/…'],
        'linkedin'  => ['LinkedIn',  '💼', 'https://www.linkedin.com/company/…'],
        'youtube'   => ['YouTube',   '▶',  'https://youtube.com/@…'],
    ];

    private static function settingKey(string $k): string
    {
        return 'social_' . $k;
    }

    /**
     * Alle Kanäle mit aktuellem Wert – für den Editor.
     * @return array<string,array{label:string,icon:string,placeholder:string,url:string}>
     */
    public static function all(): array
    {
        $out = [];
        foreach (self::CHANNELS as $k => [$label, $icon, $ph]) {
            $out[$k] = [
                'label'       => $label,
                'icon'        => $icon,
                'placeholder' => $ph,
                'url'         => trim((string) Settings::get(self::settingKey($k), '')),
            ];
        }
        return $out;
    }

    /**
     * Nur gepflegte (nicht-leere) Kanäle – für die Anzeige.
     * @return array<string,array{label:string,icon:string,url:string}>
     */
    public static function links(): array
    {
        $out = [];
        foreach (self::all() as $k => $c) {
            if ($c['url'] !== '') {
                $out[$k] = ['label' => $c['label'], 'icon' => $c['icon'], 'url' => $c['url']];
            }
        }
        return $out;
    }

    /** Kanäle speichern. @param array<string,string> $urls key => URL (leer = löschen). */
    public static function save(array $urls): void
    {
        foreach (array_keys(self::CHANNELS) as $k) {
            $url = trim((string) ($urls[$k] ?? ''));
            Settings::set(self::settingKey($k), $url !== '' ? $url : null);
        }
    }
}
