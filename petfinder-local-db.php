<?php
/*
Plugin Name: Petfinder Local DB
Plugin URI:
Description: This plugin extends Petfinder API For WP to manage a local copy of the Petfinder database
Version: 1.4
Author: Brian "Maverick" Peppers
Author URI:
License:
*/
if( !defined( 'ABSPATH' ) ) {
    die();
}

$plugin_dir = WP_PLUGIN_DIR . '/pet-finder-plugin';
include_once $plugin_dir.'/require/Petfinder.php';

if(!class_exists("PetfinderLocalDB")) {
    class PetfinderLocalDB extends PetFinder {

        # ------------------------ #
        # --- MEMBER VARIABLES --- #
        # ------------------------ #

        private $version    = "1.3";
        private $table_name = "petfinder_pets";
        private $is_ready   = false;

        protected static $_instance = null;

        # ------------------------ #
        # -- CONSTRUCTOR METHOD -- #
        # ------------------------ #

        /*
         * Constructor for this class. Integrates the plugin into the WP environment.
         */

        function __construct() {
            $this->installPlugin();
            $this->hookPlugin();
        }

        # ------------------------ #
        # --- ACCESSOR METHODS --- #
        # ------------------------ #

        /**
         * checks configuration and returns if plugin is configured
         *
         * @return bool
         */
        public function isConfigured() {
            $this->checkConfiguration();

            return $this->is_ready;
        }

        /**
         * Singleton pattern. Creates a static instance of itself if none exist.
         * Returns a reference to itself.
         *
         * @return null|PetfinderLocalDB
         */
        static function instance() {
            if( is_null( self::$_instance ) ) {
                self::$_instance = new self();
            }

            return self::$_instance;
        }

        /**
         * Returns the table name
         *
         * @return string
         */
        public function getTableName() {
            return $this->table_name;
        }

        /**
         * Returns the version number
         *
         * @return string
         */
        public function getVersion() {
            return $this->version;
        }

        /**
         * Returns the shelter ID
         *
         * @return mixed json object notation
         */
        public function getShelterID() {
            return get_option('petfinder-shelter-id');
        }

        /**
         * Returns dogs from the database
         *
         * @param int $count default: 10 dogs
         * @return mixed json object notation
         */
        public function getDogs($count=10) {
            return json_decode( json_encode( $this->getDBResults(array("status" => "A", "count" => "$count", "animal" => "DOG") ) ) );
        }

        /**
         * Returns cats from the database
         *
         * @param int $count default: 10 cats
         * @return mixed json object notation
         */
        public function getCats($count=10) {
            return json_decode( json_encode( $this->getDBResults(array("status" => "A", "count" => "$count", "animal" => "CAT") ) ) );
        }

        # ------------------------ #
        # ---- PUBLIC METHODS ---- #
        # ------------------------ #

        /**
         * Checks wp options. If they are set, assign the keys in the parent constructor.
         * isConfigured() will return true if set.
         */
        public function checkConfiguration() {
            if(!empty(get_option('petfinder-shelter-id'))
                && !empty(get_option('petfinder-api-key'))
                && !empty(get_option('petfinder-api-secret'))) {

                parent::__construct(get_option('petfinder-api-key'), get_option('petfinder-api-secret'));
                $this->is_ready = true;
            }else {
                $this->is_ready = false;
            }
        }

        /*
         * Hook into the WP system, admin panel, and create a settings page
         */
        public function hookPlugin() {
            add_action('admin_menu', array( $this, 'addInterface') );
            add_action('admin_init', array( $this, 'registerSettings') );
            add_action('init' ,      array( $this, 'registerShortCodes') );
        }

        /**
         * Register short-codes in wp
         */
        public function registerShortCodes() {
            add_shortcode('petfinder-display-pets', array( $this, 'outputDB'));
            add_shortcode('petfinder-update-pets',  array( $this, 'updateDB'));
        }

        /*
         * Update plugin options in WP and create a table in the database if necessary
         */

        public function installPlugin() {
            global $wpdb;

            $table = $wpdb->prefix.$this->getTableName();

            // Check if this null, then do a full install
            $installed_petfinder_version = get_option('petfinder-plugin-version');

            # !!! CHECK FOR VERSION CONFLICTS
            if(empty($installed_petfinder_version)) {
                // modify table
                $sql = "CREATE TABLE $table (
                    id bigint(11) NOT NULL AUTO_INCREMENT,
                    pf_id bigint(11) NOT NULL,
                    animal varchar(11) NOT NULL,
                    breed text NOT NULL,
                    mix varchar(3) NOT NULL,
                    age varchar(8) NOT NULL,
                    name varchar(28) NOT NULL,
                    size varchar(11) NOT NULL,
                    sex varchar(11) NOT NULL,
                    descr text NOT NULL,
                    lastupdate datetime NOT NULL,
                    status varchar(11) NOT NULL,
                    videos varchar(256) NOT NULL,
                    isfoster tinyint(1) NOT NULL,
                    wpImageID text NOT NULL,
                    PRIMARY KEY  (id),
                    UNIQUE KEY  (pf_id)
                    );";

                require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
                dbDelta( $sql );

                update_option( "petfinder-plugin-version", $this->getVersion() );
            }
        }

        /*
         * Delete plugin options in WP ad drop a table database
         */
        public function uninstallPlugin() {
            global $wpdb;

            delete_option('petfinder-version-no');
            delete_option('petfinder-api-key');
            delete_option('petfinder-api-secret');
            delete_option('petfinder-shelter-id');

            $wpdb->query("DROP TABLE IF EXISTS ".$wpdb->prefix.$this->getTableName()."");
        }

        # ------------------------ #
        # ---- PUBLIC METHODS ---- #
        # - SHORT CODE CALLBACKS - #
        # ------------------------ #

        /**
         * When invoked, the local db is updated.
         * Adopted pets are dropped.
         * Pictures are uploaded to the server and attached as media in wp.
         * Returns string if updated or not updated.
         *
         * @return string
         */
        public function updateDB() {

            if(!$this->isConfigured()){
                return "not updated";
            }

            global $wpdb;

            $count = 400; // Request up to 400 pets

            // inherited class function 'shelter_getPets()'
            $petfinder_xml_data = $this->shelter_getPets(
                array(
                    'count' =>  $count,
                    'id' => strtoupper($this->getShelterID()),
                    'status' => 'A',
                    'output' => 'full'
                )
            );

            // Converts unreliable xml string format to a reliable object with accessible properties
            // LIBXML_NOCDATA -- enables CDATA content
            $petfinder_xml_data = @simplexml_load_string( $petfinder_xml_data, null, LIBXML_NOCDATA );

            // print_r($petfinder_xml_data);

            $petfinder_xml_data = json_decode( json_encode( $petfinder_xml_data ) );

            $petfinder_db_data  = $this->getDBResults();


            if($petfinder_xml_data->header->status->code == '100') { # 100 is OK

                # echo "HEADER OK<br/>";

                $new_pets = null; // array to keep track of new incoming pets

                $output = '';
                // If there any pets in the request that match with the local database, update those pets.
                foreach($petfinder_xml_data->pets->pet as $xml_pet) {

                    $db_pet_key = $this->doesPetKeyPairExist($petfinder_db_data, 'pf_id', $xml_pet->id );

                    if($db_pet_key < 0) { // Pet not found in the database
                        # echo 'not found <br/>';
                        # echo 'pet: '.$xml_pet->id."<br/>";
                        $new_pets[] = $xml_pet;
                    } else { // Pet found, update the important information
                            // Updates status and description
                            $wpdb->update( $wpdb->prefix.$this->getTableName(),
                                array('status'=>strtoupper($xml_pet->status),
                                    'descr'=>$xml_pet->description,
                                ),
                                array('pf_id'=>$xml_pet->id),// Update WHERE pf_id = $pet.pf_id
                                array( '%s',
                                       '%s'
                                ),
                                array( '%d' )
                            ); // updates status for existing pets
                    }
                }

                // If there are new pets, insert them (can occur if the database is empty)
                // There are new pets when the count of the pets in database is not equal to the amount
                // queried from the api.
                if(count($new_pets) > 0) {
                    $this->insertNewPets($new_pets);
                }
            } else {
                # echo "HEADER BAD<br/>";
            }

            $this->dropPets(); // Drops pets with adopted status

            return "updated";
        }

        /**
         * Formats all pet output as HTML.
         *
         * @param $atts
         * @return string
         */
        public function outputDB($atts) {
            $output = '';

            // NOTE: lowercase or uppercase passed arguments
            // Extract shortcode values
            $default_atts = array(
                'count'      => '20',
                'animal'     => 'CAT',
                'status'     => 'A',
                'image_size' => ''
            );

            foreach($atts as &$att) {
                $att = strtoupper($att);
            }

            $diff = array_diff($atts, $default_atts); // get function names only

            extract(shortcode_atts($default_atts, $atts));

            $petfinder_data = $this->getDBResults(array("status" => $status));

            // Compile information
            foreach($petfinder_data as $pet) {

                if(strtoupper($pet->animal) != $animal) {
                    continue;
                }else{
                    if(count($diff) > count($default_atts)) {
                        $output .= $this->petStep($pet, $diff);
                    } else {
                        $output .= $this->basicFormat($pet, $image_size);
                    }
                }
            }

            return $output;
        }

        # ----------------------- #
        # --- PRIVATE METHODS --- #
        # ------ UTILITIES ------ #
        # ----------------------- #

        /**
         * Step function for each pet. Formats output as a string.
         *
         * @param $pet
         * @param $func_array
         * @return string
         */
        private function petStep($pet, $func_array) {
            $output = '';

            // Each function executes in the order they are placed
            foreach($func_array as $func) {
                if(function_exists($this->$func)) {
                    $output .= $this->$func($pet);
                }
            }

            return $output;
        }

        /**
         * Return all row results from the local db
         *
         * @param array $atts filter results
         * @return mixed
         */
        private function getDBResults($atts) {
            global $wpdb;

            // NOTE: lowercase or uppercase passed arguments
            // Extract values
            $pairs = array(
                'count'     => '20',
                'animal'    => '',
                'status'    => 'A',
            );

            foreach($atts as &$att) {
                $att = strtoupper($att);
            }

            $extracted = shortcode_atts($pairs, $atts);

            // ==== BUILD THE QUERY ====
            $where_clause = ' WHERE '; // compile an SQL WHERE clause

            $i = 0;
            foreach($extracted as $key => $value){
                if($key == 'count'){ // count is reserved for the SQL LIMIT CLAUSE
                    continue;
                }

                if($key == 'animal' && $value == '') {
                    continue; // We don't want empty strings. This also serves to retrieve all animals.
                }

                if($i != 0){
                    $where_clause .= " AND ";
                }

                $where_clause .= $key ."='".$value."'";

                $i++;
            }

            # echo "where clause: ".$where_clause;

            $limit = " LIMIT 0,".$extracted['count'];

            $query = $where_clause;

            if($extracted['count'] > 0){
                $query.=$limit;
            }

            $query .= ";";

            $query = "SELECT * FROM ".$wpdb->prefix.$this->getTableName().$query;

            # echo $query;

            // ==== END BUILD ====

            //gets adoptable pet IDs from db
            if($status === '') // get all
            {
                $pets = $wpdb->get_results("SELECT * FROM ".$wpdb->prefix.$this->getTableName().";", 'ARRAY_A');
            }
            else{
                $pets = $wpdb->get_results($query);
            }

            return $pets;
        }

        /**
         * Drop pets from the local db
         *
         * @param array $where default: adopted pets are dropped  array('status' => 'X')
         */
        private function dropPets($where=array('status' => 'X')) {
            global $wpdb;

            foreach($where as $key=>$value){
                $query = $wpdb->prepare("DELETE FROM ".$wpdb->prefix.$this->getTableName()." WHERE $key = '".$value."'");
                $wpdb->query($query);

                # echo 'key: '.$key.'<br/>';
                # echo 'value: '.$value.'<br/>';
                # echo 'query: '.$query.'<br/>';
            }
        }

        /**
         * Download the photos and
         * attach into the Media Library.
         * Return a media id
         *
         * @param $xml_pet
         */
        private function handlePhotos($xml_pet) {
            // as of 5/18/2014 - Maverick Peppers
            // works when $subject is in the form:
            // http://photos.petfinder.com/photos/pets/22703137/1/?bust=1334107343&amp;width=60&amp;-pnt.jpg
            // url format is .../photos/pets/{pet id}/{image number}/{size option}.{file type}
            // size_option = { -x, -fpm, -t, -pn, -pnt }


            // This pattern will strip the following information from the url:
            // 0.   omitted            -----
            // 1.	`pets`             -----
            // 2.	{pet id}           ex. `22703137`
            // 3.	{image number}     ex. `1`
            // 4.	{size option}      ex. `pnt`
            // 5.   {file type}        ex. `jpg`
            $pattern = '%(pets)/(\d*)/(\d*)/\?[\w*=?&?amp?;?]+[\-](\w+)\.(\w+)%';

            foreach($xml_pet->media->photos->photo as $photo_url) {
                $matches = null;

                preg_match($pattern, $photo_url, $matches);

                # echo "<h2>matches array: </h2><br/>";
                # print_r($matches);
                # echo "<br/><br/>";

                if(count($matches == 6)) {
                    if($matches[4] == 'x') { // The biggest size we want
                        $filename = $matches[1]."-".$matches[2]."-".$matches[3]."-".$matches[4].".".$matches[5];

                        #if(wp_mkdir_p(wp_upload_dir().'pf/')) {
                        $upload_dir  = wp_upload_dir(); #.'pf/';
                        $upload_file = $this->uploadPhoto($photo_url, $upload_dir, $filename);

                        $wp_filetype = wp_check_filetype( basename( $filename ) , null );

                        $attachment = array(
                            'guid' => $upload_dir['baseurl'] . '/' . _wp_relative_upload_path( $upload_file ),
                            'post_mime_type' => $wp_filetype['type'],
                            'post_title' => $xml_pet->name,
                            'post_content' => $xml_pet->desc,
                            'post_status' => 'inherit'
                        );

                        $attach_id = wp_insert_attachment( $attachment , $upload_file, null );


                        $imageIDS[] = $attach_id;

                        require_once(ABSPATH . 'wp-admin/includes/image.php');

                        $attach_data = wp_generate_attachment_metadata( $attach_id , $upload_file );

                        wp_update_attachment_metadata( $attach_id , $attach_data );

                        if ( !empty( $imageIDS ) ) {
                            global $wpdb;
                            global $petfinder_table_name;
                            $table = $wpdb->prefix.$this->getTableName();
                            $pf_id = $xml_pet->id;

                            $sql = "UPDATE $table SET `wpImageID` = '" . serialize($imageIDS) . "' WHERE `pf_id` = $pf_id;";
                            $wpdb->query($sql);
                        }
                        # }
                    }
                }
            }
        }

        /**
         * Uploads a photo to the 'upload' directory and returns the file URI
         *
         * @param $photo_url url to photo
         * @param $upload_dir directory to place photo
         * @param $new_filename new name for the photo
         * @return string retuns new file location
         */
        private function uploadPhoto($photo_url, $upload_dir, $new_filename) {
            $upload_file = $upload_dir['path'] . '/' . $new_filename;

            $contents = file_get_contents($photo_url);
            $save_file = fopen($upload_file, 'w');
            fwrite($save_file, $contents);
            fclose($save_file);

            return $upload_file;
        }

        /*
         * Format the videos for insertion in the WP database TODO
         */
        private function serializeVideos() {
            /* //serialize videos
            $video_string = '';
            foreach($xml_pet->videos as $video) {
            $video_string .= $video . '|';
            }

            $video_string = rtrim($video_string, '|');

            return $video_string;*/
        }

        /**
         * Format the breeds for insertion in the WP database
         *
         * @param $xml_pet
         * @return string all breeds as one string
         */
        private function serializeBreeds($xml_pet) {
            //serialize breeds
            $breed_string = '';
            foreach($xml_pet->breeds as $breed) {
                if( count( $breed->breed ) > 1 ) {
                    foreach($breed as $subbreed) {
                        $breed_string .= $subbreed . ' ';
                    }
                } else {
                    $breed_string .= (string)$breed->breed . ' ';
                }
            }

            $breed_string = trim( $breed_string );

            return $breed_string;
        }

        /**
         * Given xml data for a pet, insert it correctly into the WP database
         *
         * @param $xml_pets
         */
        private function insertNewPets($xml_pets) {
            global $wpdb;

            # echo "--inserting--<br/>";

            foreach($xml_pets as $xml_pet) {

                # var_dump($xml_pet);
                # echo "<br/><br/>";

                $video_string = ''; # petfinder_serialize_videos($xml_pet);
                $breed_string =  $this->serializeBreeds($xml_pet);


                $row = array('pf_id' => (int)$xml_pet->id, 'animal' => strtoupper((string)$xml_pet->animal),
                    'breed' => (string)$breed_string, 'mix' => (string)$xml_pet->mix,
                    'age' => (string)$xml_pet->age, 'name' => (string)$xml_pet->name,
                    'size' => (string)$xml_pet->size, 'sex' => (string)$xml_pet->sex,
                    'descr' => (string)$xml_pet->description, 'lastupdate' => (string)$xml_pet->lastUpdate,
                    'status' => strtoupper((string)$xml_pet->status), 'videos' => (string)$video_string);

                $row_format = array( '%d' , '%s' , '%s' , '%s' , '%s' , '%s' , '%s' , '%s' , '%s' , '%s' , '%s' );

                $wpdb->insert($wpdb->prefix.$this->getTableName(), $row, $row_format);

                $this->handlePhotos($xml_pet);
            }
        }

        /**
         * Search  DB for a pet with an attribute (key) with a specific value
         *
         * @param $petfinder_db_data
         * @param $key_name key to look for
         * @param $desired_value the values to look for in key
         * @return int|string returns the pet key (in the db array) or -1 if no match
         */
        private function doesPetKeyPairExist($petfinder_db_data, $key_name, $desired_value) {
            foreach($petfinder_db_data as $db_pet_key => $db_pet) {
                # echo 'db_pet_key: '.$db_pet_key.'<br/>';
                # echo 'db_pet: '; print_r($db_pet); echo '<br/>';
                # echo 'desired_value: '.$desired_value.'<br/>';
                # echo 'db_pet[key_name]: '.$db_pet[$key_name].'<br/>';

                if($db_pet[$key_name] == $desired_value) {
                    return $db_pet_key;
                }
            }

            return -1;
        }

        /**
         * Format the db results as HTML code
         *
         * @param $pet
         * @return string HTML code
         */
        private function basicFormat($pet, $image_size) {
            $desc = substr($pet->descr, 0, 155).'. . . ';

            $output = '';

            $output .= '<div style="float:left; display:inline-block;">';
            $output .= '<article><header><a href="http://petfinder.com/petdetail/'.$pet->pf_id.'">';

            if($image_size == ''){
                $output .= wp_get_attachment_image(unserialize($pet->wpImageID)[0])."</a></header>";
            }
            else {
                $output .= wp_get_attachment_image(unserialize($pet->wpImageID)[0], $image_size)."</a></header>";
            }

            $output .= "<div class='post-excerpt'><h1>".$pet->name.'</h1>';
            $output .= '<p>'.$desc.'<a class="button pink" href="/adopt/adoption-process/" title="Adopt a pet now">Adopt me</a></p>';
            $output .= '<br /><h4><a rel="nofollow" href="http://petfinder.com/petdetail/' . $pet->pf_id . '" target="_blank" title="Learn more about ' . $pet->name;
            $output .= '">Find out more about ' . $pet->name . '</a></h4><br /></div>';
            $output .= '</div>';

            return $output;
        }

        # --------------------------------- #
        # ------ PET FINDER ADMIN WP ------ #
        # --------------------------------- #

        /*
         *  Register a callback function to generate an HTML page for editable plugin settings
         */
        public function addInterface() {
            add_options_page('Pet Finder WP', 'Pet Finder WP', '1', 'functions',
                array( $this, 'editablePlugin') );
        }

        /*
         * Register options in the WP options table
         */
        public function registerSettings() { // whitelist options
            register_setting( 'petfinder-option-group', 'petfinder-api-key' );
            register_setting( 'petfinder-option-group', 'petfinder-api-secret');
            register_setting( 'petfinder-option-group', 'petfinder-shelter-id' );
        }

        /*
         * Displays dynamic HTML results generated [NOTE: remove echo]
         */
        public function editablePlugin() {
            # $installed_petfinder_version = get_option('petfinder-plugin-version');

            #echo "installed version: ".$this->getVersion()."<br/>";
            #echo "plugin version: ".$this->getVersion()."<br/>";
            ?>

            <div class="wrap">
                <h2>Pet Finder WP</h2><br/>
                Petfinder Pluggin by Image In A Box<br />
                <form method="post" action="options.php">
                    <?php settings_fields('petfinder-option-group');
                    do_settings_sections('petfinder-option-group'); ?>

                    <p>
                        <strong>Pet Finder API Key:</strong><br />
                        <input type="text" name="petfinder-api-key" size="45"
                               value="<?php echo get_option('petfinder-api-key'); ?>" />
                    </p>

                    <p><strong>Pet Finder API Secret:</strong><br />
                        <input type="text" name="petfinder-api-secret" size="45"
                               value="<?php echo get_option('petfinder-api-secret'); ?>" />
                    </p>

                    <p><strong>Shelter ID:</strong><br />
                        <input type="text" name="petfinder-shelter-id" size="45"
                               value="<?php echo get_option('petfinder-shelter-id'); ?>" />
                    </p>
                    <p>
                        <?php submit_button(); ?>
                    </p>
                </form>

            </div>
        <?php
        }

    }

    # ---------------------------------- #
    # --- END CLASS PetfinderLocalDB --- #
    # ---------------------------------- #

}

# --------------------------- #
# --- END IF CLASS EXISTS --- #
# --------------------------- #

# ------------------------ #
# --- GLOBAL FUNCTIONS --- #
# ------------------------ #

/**
 * Returns an instance of the PetfinderLocalDB singelton
 *
 * @return null|PetfinderLocalDB
 */
function PetfinderLocalDB() {
    return PetfinderLocalDB::instance();
}

/**
 * Stores the global instance of the PetfinderLocalDB singelton.
 */
function setPetfinderLocalDB() {
    $GLOBALS['petfinder_local_db'] = PetfinderLocalDB();
}

/*
 * Add action to word press. On init, instantialize the PetfinderLocalDB plugin
 */
add_action( 'init' , 'setPetfinderLocalDB' , 0 );

# --------------------------- #
# ------- END PHP FILE ------ #
# --------------------------- #
?>
