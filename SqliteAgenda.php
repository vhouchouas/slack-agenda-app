<?php

use Monolog\Logger;
use Sabre\VObject;

class SqliteAgenda extends Agenda {
    private $path;

    public function __construct(string $CalDAV_url, string $CalDAV_username, string $CalDAV_password, object $api, array $agenda_args) {
        $this->log = new Logger('sqliteAgenda');
        $this->path = $agenda_args["path"];
        $this->table_prefix = $agenda_args["db_table_prefix"];
        parent::__construct($CalDAV_url, $CalDAV_username, $CalDAV_password, $api);
    }
    
    protected function openDB() {
        try{
            $pdo = new PDO("sqlite:$this->path");
            $pdo->exec("PRAGMA foreign_keys = ON;"); // needed for ON DELETE CASCADE
            return $pdo;
        } catch(Exception $e) {
            echo "Can't reach SQLite database: ".$e->getMessage();
            die();
        }
    }
    
    public function createDB() {
        $this->log->info("Create database tables...");
        
        $this->pdo->query("CREATE TABLE IF NOT EXISTS {$this->table_prefix}events ( 
    vCalendarFilename               VARCHAR( 256 ) PRIMARY KEY,
    ETag                            VARCHAR( 256 ),
    datetime_begin                  DATETIME,
    number_volunteers_required      INT,
    vCalendarRaw                    TEXT);");

        $this->pdo->query("CREATE TABLE IF NOT EXISTS {$this->table_prefix}categories ( 
    id                              INTEGER PRIMARY KEY AUTOINCREMENT,
    name                            VARCHAR( 64 ) UNIQUE);");

        $this->pdo->query("CREATE TABLE IF NOT EXISTS {$this->table_prefix}events_categories ( 
    category_id                     INTEGER,
    vCalendarFilename               VARCHAR( 256 ),
    FOREIGN KEY (category_id)       REFERENCES {$this->table_prefix}categories(id) ON DELETE CASCADE,
    FOREIGN KEY (vCalendarFilename) REFERENCES {$this->table_prefix}events(vCalendarFilename) ON DELETE CASCADE
    );");
        
        $this->pdo->query("CREATE TABLE IF NOT EXISTS {$this->table_prefix}attendees ( 
    email                           VARCHAR( 256 ) PRIMARY KEY,
    userid                          VARCHAR( 11 ));");

        $this->pdo->query("CREATE TABLE IF NOT EXISTS {$this->table_prefix}events_attendees (
    vCalendarFilename               VARCHAR( 256 ),
    email                           VARCHAR( 256 ),
    FOREIGN KEY (email)             REFERENCES {$this->table_prefix}attendees(email) ON DELETE CASCADE,
    FOREIGN KEY (vCalendarFilename) REFERENCES {$this->table_prefix}events(vCalendarFilename) ON DELETE CASCADE
    );");

        $this->pdo->query("CREATE TABLE IF NOT EXISTS {$this->table_prefix}properties ( 
    property                        VARCHAR( 256 ) PRIMARY KEY,
    value                           VARCHAR( 256 ));");

        $query = $this->pdo->prepare("INSERT OR IGNORE INTO {$this->table_prefix}properties (property, value) VALUES ('CTag', 'NULL')");
        $query->execute();
        $this->log->info("Create database tables - done.");
    }
}
