<?php
namespace Qkly;

class Command
{
    public function __construct(protected $argv = [])
    {
        $this->dispatcher();
    }

    private function dispatcher()
    {
        if (isset($this->argv[1])) {
            switch ($this->argv[1]) {
                case 'migrate':
                    Database::migrate();
                    break;
                case 'migrate:fresh':
                    Database::migrate(true);
                    break;
                case 'make:migration':
                    if (isset($this->argv[2])) {
                        Database::makeMigration($this->argv[2]);
                    } else {
                        print "Migration name should be passed\n";
                    }
                    break;
            }
        } else {
            print "No arguments passed\n";
            print "make:migration - Creates new migration with passed name. Usage: php carbon make:migration table_name\n";
            print "migrate - Starts migrating unmigrated migragions\n";
            print "migrate:fresh - Drops all tables and starts fres migration\n";
        }
    }
}
