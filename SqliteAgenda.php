<?php

require_once "agenda.php";
use Monolog\Logger;
use Sabre\VObject;


class SqliteAgenda extends Agenda {
    private $path;

    public function __construct(ICalDAVClient $caldav_client, object $api, array $agenda_args) {
        $this->path = $agenda_args["path"];
        parent::__construct($agenda_args["db_table_prefix"], new Logger('sqliteAgenda'), $caldav_client, $api);
    }
    
    protected function openDB() {
        try{
            $pdo = new PDO("sqlite:$this->path");
            $pdo->exec("PRAGMA foreign_keys = ON;"); // needed for ON DELETE CASCADE
            return $pdo;
        } catch(Exception $e) {
            echo "Can't reach SQLite database: ".$e->getMessage();
            die(1);
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
    userid                          VARCHAR( 11 ) NULL
    );");

        $this->pdo->query("CREATE TABLE IF NOT EXISTS {$this->table_prefix}events_attendees (
    vCalendarFilename               VARCHAR( 256 ),
    email                           VARCHAR( 256 ),
    FOREIGN KEY (email)             REFERENCES {$this->table_prefix}attendees(email) ON DELETE CASCADE,
    FOREIGN KEY (vCalendarFilename) REFERENCES {$this->table_prefix}events(vCalendarFilename) ON DELETE CASCADE
    );");

        $this->pdo->query("CREATE TABLE IF NOT EXISTS {$this->table_prefix}properties ( 
    property                        VARCHAR( 256 ) PRIMARY KEY,
    value                           VARCHAR( 256 ));");

        $this->pdo->query("CREATE TABLE IF NOT EXISTS {$this->table_prefix}reminders ( 
    id                              VARCHAR( 12 ) PRIMARY KEY,
    vCalendarFilename               VARCHAR( 256 ),
    userid                          VARCHAR( 11 ),
    FOREIGN KEY (vCalendarFilename) REFERENCES {$this->table_prefix}events(vCalendarFilename) ON DELETE CASCADE
    );");

        $this->insertMandatoryLinesAfterDbInitialization();

        $this->log->info("Create database tables - done.");
    }

    protected function insertIgnorePrefix() {
        return "INSERT OR IGNORE";
    }
}
