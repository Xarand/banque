<?php
namespace App;
final class Url {
    public static function base(): string {
        // Si tu restes en /banque/public/, adapte :
        $script = $_SERVER['SCRIPT_NAME'] ?? '';
        $pos = strpos($script, '/public/');
        if ($pos !== false) {
            return substr($script, 0, $pos) . '/public';
        }
        return '/banque/public';
    }
    public static function to(string $path, array $params=[]): string {
        $url = rtrim(self::base(), '/') . '/' . ltrim($path,'/');
        if ($params) {
            $url .= '?' . http_build_query($params);
        }
        return $url;
    }
}