<?php

use Monolog\Logger;
use Sabre\VObject;

class MySQLAgenda extends Agenda {
    private $db_name;
    private $host;
    private $username;
    private $password;
    
    public function __construct(string $CalDAV_url, string $CalDAV_username, string $CalDAV_password, object $api, array $agenda_args) {
            $this->log = new Logger('MySQLAgenda');
            $this->db_name = $agenda_args["db_name"];
            $this->host = $agenda_args["db_host"];
            $this->username = $agenda_args["db_username"];
            $this->password = $agenda_args["db_password"];
            parent::__construct($CalDAV_url, $CalDAV_username, $CalDAV_password, $api);
        }
    
    protected function openDB() {
        try{
            return new PDO("mysql:host=$this->host;dbname=$this->db_name",
                           $this->username,
                           $this->password);
        } catch(Exception $e) {
            echo "Can't reach MySQL like database: ".$e->getMessage();
            die();
        }
    }
    
    public function createDB() {
        $this->log->info("Create database tables...");
        $this->pdo->query("CREATE TABLE IF NOT EXISTS events ( 
    vCalendarFilename               VARCHAR( 256 ) PRIMARY KEY,
    ETag                            VARCHAR( 256 ),
    datetime_begin                  DATETIME,
    number_volunteers_required      INT,
    vCalendarRaw                    TEXT)  DEFAULT CHARSET=utf8mb4;");

        $this->pdo->query("CREATE TABLE IF NOT EXISTS categories ( 
    id                              INTEGER PRIMARY KEY AUTO_INCREMENT,
    name                            VARCHAR( 64 ),
    UNIQUE (name)
    )  DEFAULT CHARSET=utf8mb4;");

        $this->pdo->query("CREATE TABLE IF NOT EXISTS events_categories ( 
    category_id                     INTEGER,
    vCalendarFilename               VARCHAR( 256 ),
    FOREIGN KEY (category_id)       REFERENCES categories(id) ON DELETE CASCADE,
    FOREIGN KEY (vCalendarFilename) REFERENCES events(vCalendarFilename) ON DELETE CASCADE
    )  DEFAULT CHARSET=utf8mb4;");
        
        $this->pdo->query("CREATE TABLE IF NOT EXISTS attendees ( 
    email                           VARCHAR( 256 ) PRIMARY KEY,
    userid                          VARCHAR( 11 ))  DEFAULT CHARSET=utf8mb4;");

        $this->pdo->query("CREATE TABLE IF NOT EXISTS events_attendees (
    vCalendarFilename               VARCHAR( 256 ),
    email                           VARCHAR( 256 ),
    FOREIGN KEY (email)             REFERENCES attendees(email) ON DELETE CASCADE,
    FOREIGN KEY (vCalendarFilename) REFERENCES events(vCalendarFilename) ON DELETE CASCADE
    )  DEFAULT CHARSET=utf8mb4;");

        $this->pdo->query("CREATE TABLE IF NOT EXISTS properties ( 
    property                        VARCHAR( 256 ) PRIMARY KEY,
    value                           VARCHAR( 256 ))  DEFAULT CHARSET=utf8mb4;");

        $query = $this->pdo->prepare("INSERT IGNORE INTO properties (property, value) VALUES ('CTag', 'NULL')");
        $query->execute();
        $this->log->info("Create database tables - done.");
    }
}
