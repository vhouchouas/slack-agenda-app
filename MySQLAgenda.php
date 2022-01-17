<?php

use Monolog\Logger;
use Sabre\VObject;

class MySQLAgenda extends Agenda {
    private $db_name;
    private $host;
    private $username;
    private $password;
    
    public function __construct(ICalDAVClient $caldav_client, object $api, array $agenda_args) {
            $this->db_name = $agenda_args["db_name"];
            $this->host = $agenda_args["db_host"];
            $this->username = $agenda_args["db_username"];
            $this->password = $agenda_args["db_password"];
            parent::__construct($agenda_args["db_table_prefix"], new Logger('MySQLAgenda'), $caldav_client, $api);
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
        $this->pdo->query("CREATE TABLE IF NOT EXISTS {$this->table_prefix}events ( 
    vCalendarFilename               VARCHAR( 256 ) PRIMARY KEY,
    ETag                            VARCHAR( 256 ),
    datetime_begin                  DATETIME,
    number_volunteers_required      INT,
    vCalendarRaw                    TEXT
    ) DEFAULT CHARSET=utf8mb4;");

        $this->pdo->query("CREATE TABLE IF NOT EXISTS {$this->table_prefix}categories ( 
    id                              INTEGER PRIMARY KEY AUTO_INCREMENT,
    name                            VARCHAR( 64 ),
    UNIQUE (name)
    )  DEFAULT CHARSET=utf8mb4;");

        $this->pdo->query("CREATE TABLE IF NOT EXISTS {$this->table_prefix}events_categories ( 
    category_id                     INTEGER,
    vCalendarFilename               VARCHAR( 256 ),
    FOREIGN KEY (category_id)       REFERENCES {$this->table_prefix}categories(id) ON DELETE CASCADE,
    FOREIGN KEY (vCalendarFilename) REFERENCES {$this->table_prefix}events(vCalendarFilename) ON DELETE CASCADE
    ) DEFAULT CHARSET=utf8mb4;");
        
        $this->pdo->query("CREATE TABLE IF NOT EXISTS {$this->table_prefix}attendees ( 
    email                           VARCHAR( 256 ) PRIMARY KEY,
    userid                          VARCHAR( 11 ) NULL
    ) DEFAULT CHARSET=utf8mb4;");

        $this->pdo->query("CREATE TABLE IF NOT EXISTS {$this->table_prefix}events_attendees (
    vCalendarFilename               VARCHAR( 256 ),
    email                           VARCHAR( 256 ),
    FOREIGN KEY (email)             REFERENCES {$this->table_prefix}attendees(email) ON DELETE CASCADE,
    FOREIGN KEY (vCalendarFilename) REFERENCES {$this->table_prefix}events(vCalendarFilename) ON DELETE CASCADE
    ) DEFAULT CHARSET=utf8mb4;");

        $this->pdo->query("CREATE TABLE IF NOT EXISTS {$this->table_prefix}properties ( 
    property                        VARCHAR( 256 ) PRIMARY KEY,
    value                           VARCHAR( 256 )
    ) DEFAULT CHARSET=utf8mb4;");

        $this->pdo->query("CREATE TABLE IF NOT EXISTS {$this->table_prefix}reminders ( 
    id                              VARCHAR( 12 ),
    vCalendarFilename               VARCHAR( 256 ),
    userid                          VARCHAR( 11 ),
    FOREIGN KEY (vCalendarFilename) REFERENCES {$this->table_prefix}events(vCalendarFilename) ON DELETE CASCADE
    ) DEFAULT CHARSET=utf8mb4;");

        $this->insertMandatoryLinesAfterDbInitialization();

        $this->log->info("Create database tables - done.");
    }

    protected function insertIgnorePrefix() {
        return "INSERT IGNORE";
    }
}
