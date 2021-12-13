<?php

use Monolog\Logger;
use Sabre\VObject;

class SqliteAgenda extends DBAgenda {
    public function __construct(string $CalDAV_url, string $CalDAV_username, string $CalDAV_password, object $api, array $agenda_args) {
        $this->log = new Logger('sqliteAgenda');
        parent::__construct($CalDAV_url, $CalDAV_username, $CalDAV_password, $api, $agenda_args);
    }
    
    protected function openDB(array $agenda_args) {
        try{
            return new PDO("sqlite:$agenda_args[path]");
        } catch(Exception $e) {
            echo "Can't reach SQLite database: ".$e->getMessage();
            die();
        }
    }
    
    public function createDB() {
        $this->pdo->query("CREATE TABLE IF NOT EXISTS events ( 
    vCalendarFilename               VARCHAR( 256 ) PRIMARY KEY,
    ETag                            VARCHAR( 256 ),
    datetime_begin                  DATETIME,
    number_volunteers_required      INT,
    vCalendarRaw                    TEXT);");

        $this->pdo->query("CREATE TABLE IF NOT EXISTS categories ( 
    id                              INTEGER PRIMARY KEY AUTOINCREMENT,
    name                            VARCHAR( 64 ) UNIQUE);");

        $this->pdo->query("CREATE TABLE IF NOT EXISTS events_categories ( 
    category_id                     INTEGER,
    vCalendarFilename               VARCHAR( 256 ),
    FOREIGN KEY (category_id)       REFERENCES categories(id) ON DELETE CASCADE,
    FOREIGN KEY (vCalendarFilename) REFERENCES events(vCalendarFilename) ON DELETE CASCADE
    );");
        
        $this->pdo->query("CREATE TABLE IF NOT EXISTS attendees ( 
    email                           VARCHAR( 256 ) PRIMARY KEY,
    userid                          VARCHAR( 11 ));");

        $this->pdo->query("CREATE TABLE IF NOT EXISTS events_attendees (
    vCalendarFilename               VARCHAR( 256 ),
    email                           VARCHAR( 256 ),
    FOREIGN KEY (email)             REFERENCES attendees(email) ON DELETE CASCADE,
    FOREIGN KEY (vCalendarFilename) REFERENCES events(vCalendarFilename) ON DELETE CASCADE
    );");

        $this->pdo->query("CREATE TABLE IF NOT EXISTS properties ( 
    property                        VARCHAR( 256 ) PRIMARY KEY,
    value                           VARCHAR( 256 ));");

        $query = $this->pdo->prepare("INSERT OR IGNORE INTO properties (property, value) VALUES ('CTag', 'NULL')");
        $query->execute();
    }
}
