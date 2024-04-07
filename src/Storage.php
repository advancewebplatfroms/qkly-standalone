<?php
namespace Qkly;

class Storage
{
    public static function drive()
    {
        return $_ENV['STORAGE'];
    }

    public static function init()
    {
        $drive = self::drive();
        if ($drive == 'local') {
            $root = APP_DIR . 'uploads' . DS;
            if (!file_exists($root)) {
                mkdir($root);
            }
            return ['drive' => $drive, 'root' => $root];
        }
    }


    public static function create($source)
    {
        $config = self::init();
        if ($config['drive'] == 'local') {
            if (is_array($source)) {
                $ext = pathinfo($source['name'], PATHINFO_EXTENSION);
                $fileName = 'file_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
                $dest = $config['root'] . $fileName;
                move_uploaded_file($source['tmp_name'], $dest);
                return $fileName;
            }
        } else if (self::drive() == 'ses') {

        }
        return false;
    }
}