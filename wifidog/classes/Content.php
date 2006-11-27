<?php


/* vim: set expandtab tabstop=4 shiftwidth=4 softtabstop=4: */

// +-------------------------------------------------------------------+
// | WiFiDog Authentication Server                                     |
// | =============================                                     |
// |                                                                   |
// | The WiFiDog Authentication Server is part of the WiFiDog captive  |
// | portal suite.                                                     |
// +-------------------------------------------------------------------+
// | PHP version 5 required.                                           |
// +-------------------------------------------------------------------+
// | Homepage:     http://www.wifidog.org/                             |
// | Source Forge: http://sourceforge.net/projects/wifidog/            |
// +-------------------------------------------------------------------+
// | This program is free software; you can redistribute it and/or     |
// | modify it under the terms of the GNU General Public License as    |
// | published by the Free Software Foundation; either version 2 of    |
// | the License, or (at your option) any later version.               |
// |                                                                   |
// | This program is distributed in the hope that it will be useful,   |
// | but WITHOUT ANY WARRANTY; without even the implied warranty of    |
// | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the     |
// | GNU General Public License for more details.                      |
// |                                                                   |
// | You should have received a copy of the GNU General Public License |
// | along with this program; if not, contact:                         |
// |                                                                   |
// | Free Software Foundation           Voice:  +1-617-542-5942        |
// | 59 Temple Place - Suite 330        Fax:    +1-617-542-2652        |
// | Boston, MA  02111-1307,  USA       gnu@gnu.org                    |
// |                                                                   |
// +-------------------------------------------------------------------+

/**
 * @package    WiFiDogAuthServer
 * @subpackage ContentClasses
 * @author     Benoit Grégoire <bock@step.polymtl.ca>
 * @copyright  2005-2006 Benoit Grégoire, Technologies Coeus inc.
 * @version    Subversion $Id$
 * @link       http://www.wifidog.org/
 */

/**
 * Load required classes
 */
require_once ('classes/FormSelectGenerator.php');
require_once ('classes/GenericObject.php');
require_once ('classes/Cache.php');
require_once ('classes/HyperLink.php');

/**
 * Defines any type of content
 *
 * @package    WiFiDogAuthServer
 * @subpackage ContentClasses
 * @author     Benoit Grégoire <bock@step.polymtl.ca>
 * @copyright  2005-2006 Benoit Grégoire, Technologies Coeus inc.
 */
class Content implements GenericObject {
    /**
     * Id of content
     *
     * @var string     */
    protected $id;

    /**
     * Array containg content from database
     *
     * @var array     */
    protected $content_row;

    /**
     * Type of content
     *
     * @var string
     */
    private $content_type;

    /**
     * Defines if logging is enabled or not
     *
     * @var bool
     */
    private $is_logging_enabled;

    /** Log as part of this other content */
    private $log_as_content;

    /**
     * Constructor
     *
     * @param string $content_id Id of content
     *
     * @return void
     */
    protected function __construct($content_id) {
        
        $db = AbstractDb::getObject();

        // Init values
        $row = null;

        // Get content from database
        $content_id = $db->escapeString($content_id);
        $sql = "SELECT * FROM content WHERE content_id='$content_id'";
        $db->execSqlUniqueRes($sql, $row, false);

        if ($row == null) {
            throw new Exception(_("The content with the following id could not be found in the database: ") . $content_id);
        }

        $this->content_row = $row;
        $this->id = $row['content_id'];
        $this->content_type = $row['content_type'];

        // By default content display logging is enabled
        $this->setLoggingStatus(true);
        $this->log_as_content = & $this;
    }

    /**
     * A short string representation of the content
     *
     * @return string String representation of the content
     */
    public function __toString() {
        if (empty ($this->content_row['title'])) {
            $string = _("Untitled content");
        } else {
            $title = self :: getObject($this->content_row['title']);
            $string = $title->__toString();
        }

        return $string;
    }

    /**
     * Create a new Content object in the database
     *
     * @param string $content_type The content type to be given to the new object
     * @param string $id           The id to be given to the new Content. If
     *                             null, a new id will be assigned
     *
     * @return object The newly created Content object, or null if there was an
     *                error (an exception is also trown)

     */
    public static function createNewObject($content_type = "Content", $id = null) {
        
        $db = AbstractDb::getObject();

        if (empty ($id)) {
            $contentId = get_guid();
        } else {
            $contentId = $db->escapeString($id);
        }

        if (empty ($content_type)) {
            throw new Exception(_('Content type is optionnal, but cannot be empty!'));
        } else {
            $content_type = $db->escapeString($content_type);
        }

        $sql = "INSERT INTO content (content_id, content_type) VALUES ('$contentId', '$content_type')";

        if (!$db->execSqlUpdate($sql, false)) {
            throw new Exception(_('Unable to insert new content into database!'));
        }

        $object = self :: getObject($contentId);

        // At least add the current user as the default owner
        $object->AddOwner(User :: getCurrentUser());

        // By default, make it persistent
        $object->setIsPersistent(true);

        return $object;
    }

    /**
     * Get an interface to create a new object.
     *
     * @return string HTML markup
     */
    public static function getCreateNewObjectUI() {
        // Init values
        $html = "";
        $i = 0;
        $tab = array ();

        foreach (self :: getAvailableContentTypes() as $className) {
            $tab[$i][0] = $className;
            $tab[$i][1] = $className;
            $i++;
        }

        if (empty ($tab)) {
            $html .= _("It appears that you have not installed any Content plugin !");
        } else {
            $html .= _("You must select a content type: ");
            $html .= FormSelectGenerator :: generateFromArray($tab, "TrivialLangstring", "new_content_content_type", "Content", false);
        }

        return $html;
    }

    /**
     * Process the new object interface
     *
     * Will return the new object if the user has the credentials
     * necessary (else an exception is thrown) and if the form was fully
     * filled (else the object returns null).
     *
     * @return object The node object or null if no new node was created
     */
    public static function processCreateNewObjectUI() {
        // Init values
        $retVal = null;

        $contentType = FormSelectGenerator :: getResult("new_content_content_type", "Content");

        if ($contentType) {
            $retVal = self :: createNewObject($contentType);
        }

        return $retVal;
    }

    /**
     * Get the content object, specific to it's content type
     *
     * @param string $content_id The content Id
     *
     * @return object The Content object, or null if there was an error
     *                (an exception is also thrown)

     */
    public static function getObject($content_id) {
        
        $db = AbstractDb::getObject();

        // Init values
        $row = null;

        $content_id = $db->escapeString($content_id);
        $sql = "SELECT content_type FROM content WHERE content_id='$content_id'";
        $db->execSqlUniqueRes($sql, $row, false);

        if ($row == null) {
            throw new Exception(_("The content with the following id could not be found in the database: ") . $content_id);
        }
        if (!class_exists($row['content_type'])) {
            //throw new Exception(_("The following content type isn't valid: ").$row['content_type']);
            return null;
        } else {
            $object = new $row['content_type'] ($content_id);
            return $object;
        }
    }

    /**
     * Get the list of available content type on the system
     *
     * @return array An array of class names
     */
    public static function getAvailableContentTypes() {
        // Init values
        $contentTypes = array ();
        $useCache = false;
        $cachedData = null;

        // Create new cache object with a lifetime of one week
        $cache = new Cache("ContentClasses", "ClassFileCaches", 604800);

        // Check if caching has been enabled.
        if ($cache->isCachingEnabled) {
            $cachedData = $cache->getCachedData("mixed");

            if ($cachedData) {
                // Return cached data.
                $useCache = true;
                $contentTypes = $cachedData;
            }
        }

        if (!$useCache) {
            $dir = WIFIDOG_ABS_FILE_PATH . "classes/Content";
            $dirHandle = @ opendir($dir);

            if ($dirHandle) {
                // Loop over the directory
                while (false !== ($subDir = readdir($dirHandle))) {
                    // Loop through sub-directories of Content
                    if ($subDir != '.' && $subDir != '..' && is_dir("{$dir}/{$subDir}")) {
                        // Only add directories containing corresponding initial Content class
                        if (is_file("{$dir}/{$subDir}/{$subDir}.php")) {
                            $contentTypes[] = $subDir;
                        }
                    }
                }

                closedir($dirHandle);
            } else {
                throw new Exception(_('Unable to open directory ') . $dir);
            }

            // Cleanup PHP file extensions and sort the result array
            $contentTypes = str_ireplace('.php', '', $contentTypes);
            sort($contentTypes);

            // Check if caching has been enabled.
            if ($cache->isCachingEnabled) {
                // Save results into cache, because it wasn't saved into cache before.
                $cache->saveCachedData($contentTypes, "mixed");
            }
        }

        return $contentTypes;
    }
     /**
     * Check if this specific ContentType is usable (all dependencies
     * met,etc.
     * This method is meant to be overloaded by the different content classes
     * @return true or flase
     */
    public static function isContentTypeFunctional() {
            return true;
    } 
    
    /**
     * Check if the ContentType is available on the system     *
     * @param string $classname The classname to check
     * @return true or flase
     */
    public static function isContentTypeAvailable($classname) {
        if (false === array_search($classname, Content :: getAvailableContentTypes(), true)) {
            //throw new Exception(_("The following content type isn't valid: ").$contentType);
            return false;
        } else {
            return $classname.isContentTypeFunctional();
        }
    }

    /**
     * Check if this class is a class or subclass of one of the content types given as parameter
     * *
     * @param array $candidates The classnames to check
     * @return true or flase
     */
    public static function isContentType($candidates, $classname) {
        $retval = false;
        if (false === is_array($candidates)) {
            throw new exception("classnames must be an array");
        }
        $classname_reflector = new ReflectionClass($classname);

        foreach ($candidates as $candidate) {
            $candidate_reflector = new ReflectionClass($candidate);
            //echo"classname: $classname, candidate: $candidate<br>";
            if ($classname_reflector->isSubclassOf($candidate_reflector)) {
                //The content meets the criteria
                //echo "TRUE<br>";
                $retval = true;
                break;
            }
        }
        return $retval;
    }
    
     /**
     * Check if this class is NOT any of the class or subclass of one of the content types given as parameter
     * It's the opposite of isContentType()
     * 
     * @param array $candidates The classnames to check
     * @return true or flase
     */
    public static function isNotContentType($candidates, $classname) {
        return !self::isContentType($candidates, $classname);
    }
    /**
     * Get all content
     *
     * Can be restricted to a given content type
     *
     * @param string $content_type Type of content
     *
     * @return mixed Requested content
     */
    public static function getAllContent($content_type = "") {
        
        $db = AbstractDb::getObject();

        // Init values
        $whereClause = "";
        $rows = null;
        $objects = array ();

        if (!empty ($content_type)) {
            $content_type = $db->escapeString($content_type);
            $whereClause = "WHERE content_type = '$content_type'";
        }

        $db->execSql("SELECT content_id FROM content $whereClause", $rows, false);

        if ($rows) {
            foreach ($rows as $row) {
                $objects[] = self :: getObject($row['content_id']);
            }
        }

        return $objects;
    }

    /**
     * Get a flexible interface to generate new content objects
     *
     * @param string $user_prefix      A identifier provided by the programmer
     *                                 to recognise it's generated HTML form
     * @param string $content_type_filter     If set, the created content must match the filter.  Of only one type matches,
     * the content will be of this type, otherwise, the user will have
     *                                 to choose
     *
     * @return string HTML markup
     */
    public static function getNewContentUI($user_prefix, $content_type_filter = null, $title = null) {
        
        $db = AbstractDb::getObject();

        // Init values
        $html = "";
        $html .= "<fieldset class='admin_container Content'>\n";
        if (!empty ($title)) {
            $html .= "<legend>$title</legend>\n";
        }

        $availableContentTypes = self :: getAvailableContentTypes();

        $name = "get_new_content_{$user_prefix}_content_type";

        if (!$content_type_filter) {
            //Get an empty filter
            $content_type_filter = ContentTypeFilter :: getObject(array ());
        }

        $i = 0;
        $tab = array ();
        foreach ($availableContentTypes as $className) {
            if ($content_type_filter->isAcceptableContentClass($className)) {
                $tab[$i][0] = $className;
                $tab[$i][1] = $className;
                $i++;
            }
        }
        if (count($tab) > 1) {
            $label = _("Add new Content of type") . ": ";
            $html .= "<div class='admin_element_data content_add'>";
            $html .= $label;
            $html .= FormSelectGenerator :: generateFromArray($tab, 'TrivialLangstring', $name, null, false);
            $html .= "</div>";
        } else
            if (count($tab) == 1) {
                $html .= '<input type="hidden" name="' . $name . '" value="' . $tab[0][0] . '">';
            } else {
                throw new Exception(_("No content type matches the filter."));
            }

        $name = "get_new_content_{$user_prefix}_add";

        if (count($tab) == 1) {
            $value = sprintf(_("Add a %s"), $tab[0][1]);
        } else {
            $value = _("Add");
        }

        $html .= "<div class='admin_element_tools'>";
        $html .= '<input type="submit" class="submit" name="' . $name . '" value="' . $value . '">';
        $html .= "</div>";
        $html .= "</fieldset>\n";
        return $html;
    }

    /**
     * Get the created content object, IF one was created OR get existing
     * content (depending on what the user clicked)
     *
     * @param string $user_prefix                A identifier provided by the
     *                                           programmer to recognise it's
     *                                           generated form
     * @param bool   $associate_existing_content If true it allows to get
     *                                           existing object
     *
     * @return object The Content object, or null if the user didn't create one

     */
    public static function processNewContentUI($user_prefix, $associate_existing_content = false) {
        // Init values
        $object = null;

        if ($associate_existing_content == true) {
            $name = "{$user_prefix}_add";
        } else {
            $name = "get_new_content_{$user_prefix}_add";
        }

        if (!empty ($_REQUEST[$name]) && $_REQUEST[$name] == true) {
            if ($associate_existing_content == true) {
                $name = "{$user_prefix}";
            } else {
                $name = "get_new_content_{$user_prefix}_content_type";
            }

            /*
             * The result can be either a content type or a content ID
             * depending on the form (associate_existing_content or NOT)
             */
            $contentUiResult = FormSelectGenerator :: getResult($name, null);

            if ($associate_existing_content == true) {
                $object = self :: getObject($contentUiResult);
            } else {
                $object = self :: createNewObject($contentUiResult);
            }
        }

        return $object;
    }

    /**
     * Get a flexible interface to manage content linked to a node, a network
     * or anything else
     *
     * @param string $user_prefix            A identifier provided by the
     *                                       programmer to recognise it's
     *                                       generated HTML form
     * @param string $link_table             Table to link from
     * @param string $link_table_obj_key_col Column in linked table to match
     * @param string $link_table_obj_key     Key to be found in linked table
     * @param string $default_display_page
     * @param string $default_display_area
     * @return string HTML markup

     */
    public static function getLinkedContentUI($user_prefix, $link_table, $link_table_obj_key_col, $link_table_obj_key, $default_display_page = 'portal', $default_display_area = 'main_area_middle') {
        
        $db = AbstractDb::getObject();

        // Init values
        $html = "";

        $link_table = $db->escapeString($link_table);
        $link_table_obj_key_col = $db->escapeString($link_table_obj_key_col);
        $link_table_obj_key = $db->escapeString($link_table_obj_key);

        /* Content already linked */
        $current_content_sql = "SELECT * FROM $link_table WHERE $link_table_obj_key_col='$link_table_obj_key' ORDER BY display_page, display_area, display_order, subscribe_timestamp DESC";
        $rows = null;
        $db->execSql($current_content_sql, $rows, false);

        $html .= "<table class='content_management_tools'>\n";
        $html .= "<th>" . _('Display page') . '</th><th>' . _('Area') . '</th><th>' . _('Order') . '</th><th>' . _('Content') . '</th><th>' . _('Actions') . '</th>' . "\n";
        if ($rows)
            foreach ($rows as $row) {
                $content = self :: getObject($row['content_id']);
                $html .= "<tr class='already_linked_content'>\n";
                /* Display page */
                $name = "{$user_prefix}_" . $content->GetId() . "_display_page";
                $html .= "<td>" . FormSelectGenerator :: generateFromTable('content_available_display_pages', 'display_page', 'display_page', $row['display_page'], $name, null) . "</td>\n";
                $name = "{$user_prefix}_" . $content->GetId() . "_display_area";
                $html .= "<td>" . FormSelectGenerator :: generateFromTable('content_available_display_areas', 'display_area', 'display_area', $row['display_area'], $name, null) . "</td>\n";
                $name = "{$user_prefix}_" . $content->GetId() . "_display_order";
                $html .= "<td><input type='text' name='$name' value='{$row['display_order']}' size=2 class='linked_content_order'></td>\n";
                $html .= "<td>\n";
                $html .= $content->getListUI();
                $html .= "</td>\n";
                $html .= "<td>\n";
                $name = "{$user_prefix}_" . $content->GetId() . "_edit";
                $html .= "<input type='button' class='submit' name='$name' value='" . _("Edit") . "' onClick='window.open(\"" . GENERIC_OBJECT_ADMIN_ABS_HREF . "?object_class=Content&action=edit&object_id=" . $content->GetId() . "\");'>\n";
                $name = "{$user_prefix}_" . $content->GetId() . "_erase";
                $html .= "<input type='submit' class='submit' name='$name' value='" . _("Remove") . "'>";
                $html .= "</td>\n";
                $html .= "</tr>\n";
            }

        /* Add existing content */
        $html .= "<tr class='add_existing_content'>\n";
        $name = "{$user_prefix}_new_existing_display_page";
        $html .= "<td>" . FormSelectGenerator :: generateFromTable('content_available_display_pages', 'display_page', 'display_page', $default_display_page, $name, null) . "</td>\n";
        $name = "{$user_prefix}_new_existing_display_area";
        $html .= "<td>" . FormSelectGenerator :: generateFromTable('content_available_display_areas', 'display_area', 'display_area', $default_display_area, $name, null) . "</td>\n";
        $name = "{$user_prefix}_new_existing_display_order";
        $html .= "<td><input type='text' name='$name' value='1' size=2 class='linked_content_order'></td>\n";
        $html .= "<td colspan=2>\n";
        $name = "{$user_prefix}_new_existing";
        $contentSelector = Content :: getSelectExistingContentUI($name, "AND is_persistent=TRUE AND content_id NOT IN (SELECT content_id FROM $link_table WHERE $link_table_obj_key_col='$link_table_obj_key')");
        $html .= $contentSelector;
        $html .= "</td>\n";
        $html .= "</tr>\n";

        /* Add new content */
        $html .= "<tr class='add_new_content'>\n";
        $name = "{$user_prefix}_new_display_page";
        $html .= "<td>" . FormSelectGenerator :: generateFromTable('content_available_display_pages', 'display_page', 'display_page', $default_display_page, $name, null) . "</td>\n";
        $name = "{$user_prefix}_new_display_area";
        $html .= "<td>" . FormSelectGenerator :: generateFromTable('content_available_display_areas', 'display_area', 'display_area', $default_display_area, $name, null) . "</td>\n";
        $name = "{$user_prefix}_new_display_order";
        $html .= "<td><input type='text' name='$name' value='1' size=2 class='linked_content_order'></td>\n";
        $html .= "<td colspan=2>\n";
        $name = "{$user_prefix}_new";
        $html .= self :: getNewContentUI($name, $content_type = null);
        $html .= "</td>\n";
        $html .= "</table>\n";
        return $html;
    }

    /** Get the created Content object, IF one was created
     * OR Get existing content ( depending on what the user clicked )
     * @param $user_prefix A identifier provided by the programmer to recognise it's generated form
     * @param $associate_existing_content boolean if true allows to get existing
     * object
     * @return the Content object, or null if the user didn't greate one
     */
    static function processLinkedContentUI($user_prefix, $link_table, $link_table_obj_key_col, $link_table_obj_key) {
        $db = AbstractDb::getObject();
        $link_table = $db->escapeString($link_table);
        $link_table_obj_key_col = $db->escapeString($link_table_obj_key_col);
        $link_table_obj_key = $db->escapeString($link_table_obj_key);
        /* Content already linked */
        $current_content_sql = "SELECT * FROM $link_table WHERE $link_table_obj_key_col='$link_table_obj_key'";
        $rows = null;
        $db->execSql($current_content_sql, $rows, false);
        if ($rows)
            foreach ($rows as $row) {
                $content = Content :: getObject($row['content_id']);
                $content_id = $db->escapeString($content->getId());
                $sql = null;
                $name = "{$user_prefix}_" . $content->GetId() . "_erase";
                if (!empty ($_REQUEST[$name])) {
                    $sql .= "DELETE FROM $link_table WHERE $link_table_obj_key_col='$link_table_obj_key' AND content_id = '$content_id';\n";

                } else {
                    /* Display page */
                    $name = "{$user_prefix}_" . $content->GetId() . "_display_page";
                    $new_display_page = FormSelectGenerator :: getResult($name, null);
                    if ($new_display_page != $row['display_page']) {
                        $new_display_page = $db->escapeString($new_display_page);
                        $sql .= "UPDATE $link_table SET display_page='$new_display_page' WHERE $link_table_obj_key_col='$link_table_obj_key' AND content_id = '$content_id';\n";

                    }
                    /* Display area */
                    $name = "{$user_prefix}_" . $content->GetId() . "_display_area";
                    $new_display_area = FormSelectGenerator :: getResult($name, null);
                    if ($new_display_area != $row['display_area']) {
                        $new_display_area = $db->escapeString($new_display_area);
                        $sql .= "UPDATE $link_table SET display_area='$new_display_area' WHERE $link_table_obj_key_col='$link_table_obj_key' AND content_id = '$content_id';\n";
                    }
                    /* Display order */
                    $name = "{$user_prefix}_" . $content->GetId() . "_display_order";
                    if ($_REQUEST[$name] != $row['display_order']) {
                        $new_display_order = $db->escapeString($_REQUEST[$name]);
                        $sql .= "UPDATE $link_table SET display_order='$new_display_order' WHERE $link_table_obj_key_col='$link_table_obj_key' AND content_id = '$content_id';\n";
                    }
                }
                if ($sql) {
                    $db->execSqlUpdate($sql, false);
                }
            }
        /* Add existing content */
        $name = "{$user_prefix}_new_existing_add";
        if (!empty ($_REQUEST[$name])) {
            $name = "{$user_prefix}_new_existing";
            $content = Content :: processSelectContentUI($name);
            if ($content) {
                /* Display page */
                $name = "{$user_prefix}_new_existing_display_page";
                $new_display_page = $db->escapeString(FormSelectGenerator :: getResult($name, null));
                /* Display area */
                $name = "{$user_prefix}_new_existing_display_area";
                $new_display_area = $db->escapeString(FormSelectGenerator :: getResult($name, null));
                /* Display order */
                $name = "{$user_prefix}_new_existing_display_order";
                $new_display_order = $db->escapeString($_REQUEST[$name]);
                $content_id = $db->escapeString($content->getId());
                $sql = "INSERT INTO $link_table (content_id, $link_table_obj_key_col, display_page, display_area, display_order) VALUES ('$content_id', '$link_table_obj_key', '$new_display_page', '$new_display_area', $new_display_order);\n";
                $db->execSqlUpdate($sql, false);
            }
        }
        /* Add new content */
        $name = "{$user_prefix}_new";
        $content = self :: processNewContentUI($name);
        if ($content) {
            /* Display page */
            $name = "{$user_prefix}_new_display_page";
            $new_display_page = $db->escapeString(FormSelectGenerator :: getResult($name, null));
            /* Display area */
            $name = "{$user_prefix}_new_display_area";
            $new_display_area = $db->escapeString(FormSelectGenerator :: getResult($name, null));
            /* Display order */
            $name = "{$user_prefix}_new_display_order";
            $new_display_order = $db->escapeString($_REQUEST[$name]);
            $content_id = $db->escapeString($content->getId());
            $sql = "INSERT INTO $link_table (content_id, $link_table_obj_key_col, display_page, display_area, display_order) VALUES ('$content_id', '$link_table_obj_key', '$new_display_page', '$new_display_area', $new_display_order);\n";
            $db->execSqlUpdate($sql, false);
        }
    }

    /**
     * Get an interface to pick content from all persistent content
     *
     * It either returns a select box or an extended table
     *
     * @param string $user_prefix             An identifier provided by the
     *                                        programmer to recognise it's
     *                                        generated HTML form
     * @param string $sql_additional_where    Addidional where conditions to
     *                                        restrict the candidate objects
     * @param string $content_type_filter     If set, the created content must match the filter.
     * @param string $order                   Order of output (default: by
     *                                        creation time)
     * @param string $type_interface          Type of interface:
     *                                          - "select": default, shows a
     *                                            select box
     *                                          - "table": showsa table with
     *                                            extended information
     *
     * @return string HTML markup

     */
    public static function getSelectExistingContentUI($user_prefix, $sql_additional_where = null, $content_type_filter = null, $order = "creation_timestamp", $type_interface = "select") {
        
        $db = AbstractDb::getObject();

        // Init values
        $html = '';
        $retVal = array ();
        $contentRows = null;
        if ($content_type_filter == null) {
            //Get an empty filter
            $content_type_filter = ContentTypeFilter :: getObject(array ());
        }

            if (!User :: getCurrentUser()) {
                throw new Exception(_('Access denied!'));
            }

        if ($type_interface != "table") {
            $html .= "<fieldset class='admin_container Content'>\n";

            if (!empty ($title)) {
                $html .= "<legend>$title</legend>\n";
            }

            $html .= _("Add reusable content from library") . ": ";
        }

        $name = "{$user_prefix}";

        $sql = "SELECT * FROM content WHERE 1=1 $sql_additional_where ORDER BY $order";

        $db->execSql($sql, $contentRows, false);

        if ($contentRows != null) {
            $i = 0;

            if ($type_interface == "table") {
                $html .= "<table class='content_admin'>\n";
                $html .= "<tr><th>" . _("Title") . "</th><th>" . _("Content type") . "</th><th>" . _("Description") . "</th><th></th></tr>\n";
            }

            foreach ($contentRows as $contentRow) {
                $content = Content :: getObject($contentRow['content_id']);
                //echo get_class($content)." ".$contentRow['content_id']."<br>";
                if ($content && $content_type_filter->isAcceptableContentClass(get_class($content))) {
                        if ($type_interface != "table") {
                            $tab[$i][0] = $content->getId();
                            $tab[$i][1] = $content->__toString() . " (" . get_class($content) . ")";
                            $i++;
                        } else {
                            if (!empty ($contentRow['title'])) {
                                $title = Content :: getObject($contentRow['title']);
                                $titleUI = $title->__toString();
                            } else {
                                $titleUI = "";
                            }

                            if (!empty ($contentRow['description'])) {
                                $description = Content :: getObject($contentRow['description']);
                                $descriptionUI = $description->__toString();
                            } else {
                                $descriptionUI = "";
                            }

                            $href = GENERIC_OBJECT_ADMIN_ABS_HREF . "?object_id={$contentRow['content_id']}&object_class=Content&action=edit";
                            $html .= "<tr><td>$titleUI</td><td><a href='$href'>{$contentRow['content_type']}</a></td><td>$descriptionUI</td>\n";

                            $href = GENERIC_OBJECT_ADMIN_ABS_HREF . "?object_id={$contentRow['content_id']}&object_class=Content&action=delete";
                            $html .= "<td><a href='$href'>" . _("Delete") . "</a></td>";

                            $html .= "</tr>\n";
                        }
                }
            }

            if ($type_interface != "table") {
                if (isset ($tab)) {
                    $html .= FormSelectGenerator :: generateFromArray($tab, null, $name, null, false, null, null, 40);
                    //DEBUG!! get_existing_content_
                    $name = "{$user_prefix}_add";
                    $value = _("Add");
                    $html .= "<div class='admin_element_tools'>";
                    $html .= '<input type="submit" class="submit" name="' . $name . '" value="' . $value . '">';
                    $html .= "</div>";
                } else {
                    $html .= "<div class='warningmsg'>" . _("Sorry, no elligible content available in the database") . "</div>\n";
                }
                $html .= "</fieldset>\n";
            } else {
                $html .= "</table>\n";
            }
        } else {
            $html .= "<div class='warningmsg'>" . _("Sorry, no elligible content available in the database") . "</div>\n";
        }

        return $html;
    }

    /** Get the selected Content object.
     * @param $user_prefix A identifier provided by the programmer to recognise it's generated form
     * @return the Content object
     */
    static function processSelectContentUI($user_prefix) {
        $name = "{$user_prefix}";
        if (!empty ($_REQUEST[$name]))
            return Content :: getObject($_REQUEST[$name]);
        else
            return null;
    }

    /** Get the true object type represented by this isntance
     * @return an array of class names */
    public function getObjectType() {
        return $this->content_type;
    }

    /**
     * Get content title
     * @return content a content sub-class
     */
    public function getTitle() {
        try {
            return self :: getObject($this->content_row['title']);
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Get content description
     * @return content a content sub-class
     */
    public function getDescription() {
        try {
            return self :: getObject($this->content_row['description']);
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Get content long description
     * @return content a content sub-class
     */
    public function getLongDescription() {
        try {
            return self :: getObject($this->content_row['long_description']);
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Get content project info
     * @return content a content sub-class
     */
    public function getProjectInfo() {
        try {
            return self :: getObject($this->content_row['project_info']);
        } catch (Exception $e) {
            return null;
        }
    }

    /** Set the object type of this object
     * Note that after using this, the object must be re-instanciated to have the right type
     * */
    private function setContentType($content_type) {
        $db = AbstractDb::getObject();
        $content_type = $db->escapeString($content_type);
        if (!self :: isContentTypeAvailable($content_type)) {
            throw new Exception(_("The following content type isn't valid: ") . $content_type);
        }
        $sql = "UPDATE content SET content_type = '$content_type' WHERE content_id='$this->id'";

        if (!$db->execSqlUpdate($sql, false)) {
            throw new Exception(_("Update was unsuccessfull (database error)"));
        }

    }

    /** Check if a user is one of the owners of the object
     * @param $user The user to be added to the owners list
     * @param $is_author Optionnal, true or false.  Set to true if the user is one of the actual authors of the Content
     * @return true on success, false on failure */
    public function addOwner(User $user, $is_author = false) {
        $db = AbstractDb::getObject();
        $content_id = "'" . $this->id . "'";
        $user_id = "'" . $db->escapeString($user->getId()) . "'";
        $is_author ? $is_author = 'TRUE' : $is_author = 'FALSE';
        $sql = "INSERT INTO content_has_owners (content_id, user_id, is_author) VALUES ($content_id, $user_id, $is_author)";

        if (!$db->execSqlUpdate($sql, false)) {
            throw new Exception(_('Unable to insert the new Owner into database.'));
        }

        return true;
    }

    /** Remove an owner of the content
     * @param $user The user to be removed from the owners list
     */
    public function deleteOwner(User $user, $is_author = false) {
        $db = AbstractDb::getObject();
        $content_id = "'" . $this->id . "'";
        $user_id = "'" . $db->escapeString($user->getId()) . "'";

        $sql = "DELETE FROM content_has_owners WHERE content_id=$content_id AND user_id=$user_id";

        if (!$db->execSqlUpdate($sql, false)) {
            throw new Exception(_('Unable to remove the owner from the database.'));
        }

        return true;
    }

    /**
     * Indicates display logging status
     */
    public function getLoggingStatus() {
        return $this->is_logging_enabled;
    }

    /**
     * Sets display logging status
     */
    public function setLoggingStatus($status) {
        if (is_bool($status))
            $this->is_logging_enabled = $status;
    }

    /** Get the PHP timestamp of the last time this content was displayed
     * @param $user User, Optional, if present, restrict to the selected user
     * @param $node Node, Optional, if present, restrict to the selected node
     * @return PHP timestamp (seconds since UNIX epoch) if the content has been
     * displayed before, an empty string otherwise.
     */
    public function getLastDisplayTimestamp($user = null, $node = null) {
        $db = AbstractDb::getObject();
        $retval = '';
        $sql = "SELECT EXTRACT(EPOCH FROM last_display_timestamp) as last_display_unix_timestamp FROM content_display_log WHERE content_id='{$this->id}' \n";

        if ($user) {
            $user_id = $db->escapeString($user->getId());
            $sql .= " AND user_id = '{$user_id}' \n";
        }
        if ($node) {
            $node_id = $db->escapeString($node->getId());
            $sql .= " AND node_id = '{$node_id}' \n";
        }
        $sql .= " ORDER BY last_display_timestamp DESC ";
        $db->execSql($sql, $log_rows, false);
        if ($log_rows) {
            $retval = $log_rows[0]['last_display_unix_timestamp'];
        }

        return $retval;
    }

    /** Is this Content element displayable at this hotspot, many classes override this
     * @param $node Node, optionnal
     * @return true or false */
    public function isDisplayableAt($node) {
        return true;
    }

    /** Check if a user is one of the owners of the object
     * @param $user User object:  the user to be tested.
     * @return true if the user is a owner, false if he isn't of the user is null */
    public function isOwner($user) {
        $db = AbstractDb::getObject();
        $retval = false;
        if ($user != null) {
            $user_id = $db->escapeString($user->GetId());
            $sql = "SELECT * FROM content_has_owners WHERE content_id='$this->id' AND user_id='$user_id'";
            $db->execSqlUniqueRes($sql, $content_owner_row, false);
            if ($content_owner_row != null) {
                $retval = true;
            }
        }

        return $retval;
    }
    /** Get the authors of the Content
     * @return null or array of User objects */
    public function getAuthors() {
        $db = AbstractDb::getObject();
        $retval = array ();
        $sql = "SELECT user_id FROM content_has_owners WHERE content_id='$this->id' AND is_author=TRUE";
        $db->execSqlUniqueRes($sql, $content_owner_row, false);
        if ($content_owner_row != null) {
            $user = User :: getObject($content_owner_row['user_id']);
            $retval[] = $user;
        }

        return $retval;
    }
    /** @see GenricObject
     * @return The id */
    public function getId() {
        return $this->id;
    }

    /** When a content object is set as Simple, it means that is is used merely to contain it's own data.  No title, description or other metadata will be set or displayed, during display or administration
     * @return true or false */
    public function isSimpleContent() {
        return false;
    }
    
    /** This function will be called by MainUI for each Content BEFORE any getUserUI function is called to allow two pass Content display.
     * Two pass Content display allows such things as modyfying headers, title, creating content type that accumulate content from other pieces (like RSS feeds)
     * @return null
     */    
     public function prepareGetUserUI() {
        return null;
    }  
    /** Retreives the user interface of this object.  Anything that overrides this method should call the parent method with it's output at the END of processing.
     * @param $subclass_admin_interface Html content of the interface element of a children
     * @return The HTML fragment for this interface */
    public function getUserUI($subclass_user_interface = null) {
        $html = '';
        $this->isSimpleContent() ? $isSimpleContentClass = 'isSimpleContent' : $isSimpleContentClass = null;
        $html .= "<div class='user_ui_main_outer " . get_class($this) . " $isSimpleContentClass'>\n";
        $html .= "<div class='user_ui_main_inner'>\n";

        if (!empty ($this->content_row['title']) && $this->titleShouldDisplay()) {
            $html .= "<div class='user_ui_title'>\n";
            $title = self :: getObject($this->content_row['title']);
            $title->setLogAsContent($this);
            // If the content logging is disabled, all the children will inherit this property temporarly
            if ($this->getLoggingStatus() == false)
                $title->setLoggingStatus(false);
            $html .= $title->getUserUI();
            $html .= "</div>\n";
        }

if($this->isSimpleContent())
{
    $html .= "\n$subclass_user_interface\n";
}
else{
$html .= "<table><tr>\n";
        $html .= "<td>\n$subclass_user_interface</td>\n";
        $html .= "<td>\n";
}
        $authors = $this->getAuthors();
        if (count($authors) > 0) {
            $html .= "<div class='user_ui_authors'>\n";
            $html .= _("Author(s):");
            foreach ($authors as $user) {
                $html .= $user->getUsername() . " ";
            }
            $html .= "</div>\n";
        }

        if (!empty ($this->content_row['description'])) {
            $html .= "<div class='user_ui_description'>\n";
            $description = self :: getObject($this->content_row['description']);
            $description->setLogAsContent($this);
            // If the content logging is disabled, all the children will inherit this property temporarly
            if ($this->getLoggingStatus() == false)
                $description->setLoggingStatus(false);
            $html .= $description->getUserUI();
            $html .= "</div>\n";
        }

        if (!empty ($this->content_row['project_info'])) {
            if (!empty ($this->content_row['project_info'])) {
                $html .= "<div class='user_ui_projet_info'>\n";
                $html .= "<b>" . _("Project information:") . "</b>";
                $project_info = self :: getObject($this->content_row['project_info']);
                $project_info->setLogAsContent($this);
                // If the content logging is disabled, all the children will inherit this property temporarly
                if ($this->getLoggingStatus() == false)
                    $project_info->setLoggingStatus(false);
                $html .= $project_info->getUserUI();
                $html .= "</div>\n";
            }
        }
if(!$this->isSimpleContent())
{
        $html .= "</td>\n";
        $html .= "</tr></table>\n";
}
        $html .= "</div>\n";
        $html .= "</div>\n";
        $this->logContentDisplay();
        return $html;
    }

    /** Allow logging as part of another content (usually the parent for metadata).
     * Redirects clickthrough logging to the parent's content id, and does not log
     * display */
    protected function setLogAsContent(Content $content) {
        $this->log_as_content = $content;
    }

    /** Get the last time this content was displayed
     * @param $user User, optionnal.  If present, the date is the last time the
     * content was displayed for this user
     * @param $node Node, optionnal.  If present, the date is the last time the
     * content was displayed at this node 
	 @return PHP timestamp or null */
    public function getLastDisplayedTimestamp($user = null, $node = null) {
            $retval = null;
            $log_row = null;
            $user?$user_sql=" AND user_id='{$user->getId()}' ":$user_sql='';
            $node?$node_sql=" AND node_id='{$node->getId()}' ":$node_sql='';
               $db = AbstractDb::getObject();

                $sql = "SELECT EXTRACT('epoch' FROM last_display_timestamp) as last_display_timestamp FROM content_display_log WHERE content_id='$this->id' $user_sql $node_sql ORDER BY last_display_timestamp DESC limit 1";
                $db->execSqlUniqueRes($sql, $log_row, false);
                if ($log_row != null) {
                $retval = $log_row['last_display_timestamp'];
            }
            return $retval;
    }
    
    /** Log that this content has just been displayed to the user.  Will only log if the user is logged in */
    private function logContentDisplay() {
        if ($this->getLoggingStatus() == true && $this->log_as_content->getId() == $this->getId()) {
            // DEBUG::
            //echo "Logging ".get_class($this)." :: ".$this->__toString()."<br>";
            $user = User :: getCurrentUser();
            $node = Node :: getCurrentNode();
            if ($user != null && $node != null) {
                $user_id = $user->getId();
                $node_id = $node->getId();
                $db = AbstractDb::getObject();

                $sql = "SELECT * FROM content_display_log WHERE content_id='$this->id' AND user_id='$user_id' AND node_id='$node_id'";
                $db->execSql($sql, $log_rows, false);
                if ($log_rows != null) {
                    $sql = "UPDATE content_display_log SET num_display = num_display +1, last_display_timestamp = CURRENT_TIMESTAMP WHERE content_id='$this->id' AND user_id='$user_id' AND node_id='$node_id'";
                } else {
                    $sql = "INSERT INTO content_display_log (user_id, content_id, node_id) VALUES ('$user_id', '$this->id', '$node_id')";
                }
                $db->execSqlUpdate($sql, false);
            }
        }
    }
    /** Handle replacements of hyperlinks for clickthrough tracking (if appropriate) */
    protected function replaceHyperLinks(& $html) {
        /* Handle hyperlink clicktrough logging */
        if ($this->getLoggingStatus() == true) {
            $html = HyperLink :: replaceHyperLinks($html, $this->log_as_content);
        }
        return $html;
    }

    /** Retreives the list interface of this object.  Anything that overrides this method should call the parent method with it's output at the END of processing.
     * @param $subclass_admin_interface Html content of the interface element of a children
     * @return The HTML fragment for this interface */
    public function getListUI($subclass_list_interface = null) {
        $html = '';
        $html .= "<div class='list_ui_container'>\n";
        $html .= $this->__toString() . " (" . get_class($this) . ")\n";
        $html .= $subclass_list_interface;
        $html .= "</div>\n";
        return $html;
    }

    /**
     * Retreives the admin interface of this object. Anything that overrides
     * this method should call the parent method with it's output at the END of
     * processing.
     * @param string $subclass_admin_interface HTML content of the interface
     * element of a children.
     * @return string The HTML fragment for this interface.
     */
    public function getAdminUI($subclass_admin_interface = null, $title = null) {
        $db = AbstractDb::getObject();

        $html = '';
        if(!(User :: getCurrentUser()->isSuperAdmin() || $this->isOwner(User :: getCurrentUser())))
        {
                    $html .= $this->getListUI();
                    $html .= ' '._("(You do not have access to edit this piece of content)");
        }
        else
        {
        $html .= "<fieldset class='admin_container " . get_class($this) . "'>\n";
        if (!empty ($title)) {
            $html .= "<legend>$title</legend>\n";
        }
        $html .= "<ul class='admin_element_list'>\n";
        if ($this->getObjectType() == 'Content') {
            // The object hasn't yet been typed.
            $html .= _("You must select a content type: ");
            $i = 0;

            foreach (self :: getAvailableContentTypes() as $classname) {
                $tab[$i][0] = $classname;
                $tab[$i][1] = $classname;
                $i++;
            }

            $html .= FormSelectGenerator :: generateFromArray($tab, null, "content_" . $this->id . "_content_type", "Content", false);
        } else {
            $criteria_array = array (
                array (
                    'isSimpleContent'
                )
            );
            $metadada_allowed_content_types = ContentTypeFilter :: getObject($criteria_array);

            // Content metadata
            if ($this->isSimpleContent() == false || $this->isPersistent()) {
                $html .= "<fieldset class='admin_element_group'>\n";
                $html .= "<legend>" . sprintf(_("%s MetaData"), get_class($this)) . "</legend>\n";

                /* title_is_displayed */
                $html_title_is_displayed = _("Display the title?") . ": \n";
                $name = "content_" . $this->id . "_title_is_displayed";
                $this->titleShouldDisplay() ? $checked = 'CHECKED' : $checked = '';
                $html_title_is_displayed .= "<input type='checkbox' name='$name' $checked>\n";

                /* title */
                $html .= "<li class='admin_element_item_container admin_section_edit_title'>\n";
                $html .= "<div class='admin_element_data'>\n";
                if (empty ($this->content_row['title'])) {
                    $html .= self :: getNewContentUI("title_{$this->id}_new", $metadada_allowed_content_types, _("Title:"));
                    $html .= "</div>\n";
                } else {
                    $html .= $html_title_is_displayed;
                    $title = self :: getObject($this->content_row['title']);
                    $html .= $title->getAdminUI(null, _("Title:"));
                    $html .= "</div>\n";
                    $html .= "<div class='admin_element_tools admin_section_delete_title'>\n";
                    $name = "content_" . $this->id . "_title_erase";
                    $html .= "<input type='submit' class='submit' name='$name' value='" . sprintf(_("Delete %s (%s)"), _("title"), get_class($title)) . "'>";
                    $html .= "</div>\n";
                }
                $html .= "</li>\n";
            }

            if ($this->isSimpleContent() == false) {
                /* description */
                $html .= "<li class='admin_element_item_container admin_section_edit_description'>\n";
                $html .= "<div class='admin_element_data'>\n";
                if (empty ($this->content_row['description'])) {
                    $html .= self :: getNewContentUI("description_{$this->id}_new", $metadada_allowed_content_types, _("Description:"));
                    $html .= "</div>\n";
                } else {
                    $description = self :: getObject($this->content_row['description']);
                    $html .= $description->getAdminUI(null, _("Description:"));
                    $html .= "</div>\n";
                    $html .= "<div class='admin_element_tools'>\n";
                    $name = "content_" . $this->id . "_description_erase";
                    $html .= "<input type='submit' class='submit' name='$name' value='" . sprintf(_("Delete %s (%s)"), _("description"), get_class($description)) . "'>";
                    $html .= "</div>\n";
                }
                $html .= "</li>\n";

                /* long description */
                $html .= "<li class='admin_element_item_container admin_section_edit_long_description'>\n";
                $html .= "<div class='admin_element_data'>\n";
                if (empty ($this->content_row['long_description'])) {
                    $html .= self :: getNewContentUI("long_description_{$this->id}_new", $metadada_allowed_content_types, _("Long description:"));
                    $html .= "</div>\n";
                } else {
                    $description = self :: getObject($this->content_row['long_description']);
                    $html .= $description->getAdminUI(null, _("Long description:"));
                    $html .= "</div>\n";
                    $html .= "<div class='admin_element_tools'>\n";
                    $name = "content_" . $this->id . "_long_description_erase";
                    $html .= "<input type='submit' class='submit' name='$name' value='" . sprintf(_("Delete %s (%s)"), _("long description"), get_class($description)) . "'>";
                    $html .= "</div>\n";
                }
                $html .= "</li>\n";

                /* project_info */
                $html .= "<li class='admin_element_item_container admin_section_edit_project'>\n";
                $html .= "<div class='admin_element_data'>\n";
                if (empty ($this->content_row['project_info'])) {
                    $html .= self :: getNewContentUI("project_info_{$this->id}_new", $metadada_allowed_content_types, _("Information on this project:"));
                    $html .= "</div>\n";
                } else {
                    $project_info = self :: getObject($this->content_row['project_info']);
                    $html .= $project_info->getAdminUI(null, _("Information on this project:"));
                    $html .= "</div>\n";
                    $html .= "<div class='admin_element_tools'>\n";
                    $name = "content_" . $this->id . "_project_info_erase";
                    $html .= "<input type='submit' class='submit' name='$name' value='" . sprintf(_("Delete %s (%s)"), _("project information"), get_class($project_info)) . "'>";
                    $html .= "</div>\n";
                }
                $html .= "</li>\n";
            }

            //End content medatada
            if ($this->isSimpleContent() == false || $this->isPersistent()) {
                $html .= "</fieldset>\n";
            }

            if ($this->isSimpleContent() == false || $this->isPersistent()) {

                $html .= "<fieldset class='admin_element_group'>\n";
                $html .= "<legend>" . sprintf(_("%s access control"), get_class($this)) . "</legend>\n";

                /* is_persistent */
                $html .= "<li class='admin_element_item_container admin_section_edit_persistant'>\n";
                $html .= "<div class='admin_element_label'>" . _("Is part of reusable content library (protected from deletion)?") . ": </div>\n";
                $html .= "<div class='admin_element_data'>\n";
                $name = "content_" . $this->id . "_is_persistent";
                $this->isPersistent() ? $checked = 'CHECKED' : $checked = '';
                $html .= "<input type='checkbox' name='$name' $checked onChange='submit();'>\n";
                $html .= "</div>\n";
                $html .= "</li>\n";

                /* content_has_owners */
                $html .= "<li class='admin_element_item_container content_has_owners'>\n";
                $html .= "<div class='admin_element_label'>" . _("Content owner list") . "</div>\n";
                $html .= "<ul class='admin_element_list'>\n";

                $db = AbstractDb::getObject();
                $sql = "SELECT * FROM content_has_owners WHERE content_id='$this->id'";
                $db->execSql($sql, $content_owner_rows, false);
                if ($content_owner_rows != null) {
                    foreach ($content_owner_rows as $content_owner_row) {
                        $html .= "<li class='admin_element_item_container'>\n";
                        $html .= "<div class='admin_element_data'>\n";
                        $user = User :: getObject($content_owner_row['user_id']);

                        $html .= $user->getListUI();
                        $name = "content_" . $this->id . "_owner_" . $user->GetId() . "_is_author";
                        $html .= " Is content author? ";

                        $content_owner_row['is_author'] == 't' ? $checked = 'CHECKED' : $checked = '';
                        $html .= "<input type='checkbox' name='$name' $checked>\n";
                        $html .= "</div>\n";
                        $html .= "<div class='admin_element_tools'>\n";
                        $name = "content_" . $this->id . "_owner_" . $user->GetId() . "_remove";
                        $html .= "<input type='submit' class='submit' name='$name' value='" . _("Remove owner") . "'>";
                        $html .= "</div>\n";
                        $html .= "</li>\n";
                    }
                }

                $html .= "<li class='admin_element_item_container'>\n";
                $html .= "<div class='admin_element_data'>\n";
                $add_button_name = "content_{$this->id}_add_owner_submit";
                $add_button_value = _("Add owner");
                $html .= User :: getSelectUserUI("content_{$this->id}_new_owner", $add_button_name, $add_button_value);
                $html .= "</div>\n";
                $html .= "</li>\n";
                $html .= "</ul>\n";
                $html .= "</li>\n";
                $html .= "</fieldset>\n";

            }
        }
        $html .= $subclass_admin_interface;
        $html .= "</ul>\n";
        $html .= "</fieldset>\n";
        }
        return $html;
    }
    /** Process admin interface of this object.  When an object overrides this method, they should call the parent processAdminUI at the BEGINING of processing.

    */
    public function processAdminUI() {
        if ($this->isOwner(User :: getCurrentUser()) || User :: getCurrentUser()->isSuperAdmin()) {
            $db = AbstractDb::getObject();
            if ($this->getObjectType() == 'Content') /* The object hasn't yet been typed */ {
                $content_type = FormSelectGenerator :: getResult("content_" . $this->id . "_content_type", "Content");
                $this->setContentType($content_type);
            } else {
                //Content medatada

                if ($this->isSimpleContent() == false || $this->isPersistent()) {
                    /* title_is_displayed */
                    if (!empty ($this->content_row['title'])) {
                        $name = "content_" . $this->id . "_title_is_displayed";
                        !empty ($_REQUEST[$name]) ? $this->setTitleIsDisplayed(true) : $this->setTitleIsDisplayed(false);
                    }
                    /* title */
                    if (empty ($this->content_row['title'])) {
                        $title = self :: processNewContentUI("title_{$this->id}_new");
                        if ($title != null) {
                            $title_id = $title->GetId();
                            $db->execSqlUpdate("UPDATE content SET title = '$title_id' WHERE content_id = '$this->id'", FALSE);
                        }
                    } else {
                        $title = self :: getObject($this->content_row['title']);
                        $name = "content_" . $this->id . "_title_erase";
                        if (!empty ($_REQUEST[$name]) && $_REQUEST[$name] == true) {
                            $db->execSqlUpdate("UPDATE content SET title = NULL WHERE content_id = '$this->id'", FALSE);
                            $title->delete($errmsg);
                        } else {
                            $title->processAdminUI();
                        }
                    }
                }
                if ($this->isSimpleContent() == false) {
                    /* description */
                    if (empty ($this->content_row['description'])) {
                        $description = self :: processNewContentUI("description_{$this->id}_new");
                        if ($description != null) {
                            $description_id = $description->GetId();
                            $db->execSqlUpdate("UPDATE content SET description = '$description_id' WHERE content_id = '$this->id'", FALSE);
                        }
                    } else {
                        $description = self :: getObject($this->content_row['description']);
                        $name = "content_" . $this->id . "_description_erase";
                        if (!empty ($_REQUEST[$name]) && $_REQUEST[$name] == true) {
                            $db->execSqlUpdate("UPDATE content SET description = NULL WHERE content_id = '$this->id'", FALSE);
                            $description->delete($errmsg);
                        } else {
                            $description->processAdminUI();
                        }
                    }

                    /* long description */
                    if (empty ($this->content_row['long_description'])) {
                        $long_description = self :: processNewContentUI("long_description_{$this->id}_new");
                        if ($long_description != null) {
                            $long_description_id = $long_description->GetId();
                            $db->execSqlUpdate("UPDATE content SET long_description = '$long_description_id' WHERE content_id = '$this->id'", FALSE);
                        }
                    } else {
                        $long_description = self :: getObject($this->content_row['long_description']);
                        $name = "content_" . $this->id . "_long_description_erase";
                        if (!empty ($_REQUEST[$name]) && $_REQUEST[$name] == true) {
                            $db->execSqlUpdate("UPDATE content SET long_description = NULL WHERE content_id = '$this->id'", FALSE);
                            $long_description->delete($errmsg);
                        } else {
                            $long_description->processAdminUI();
                        }
                    }

                    /* project_info */
                    if (empty ($this->content_row['project_info'])) {
                        $project_info = self :: processNewContentUI("project_info_{$this->id}_new");
                        if ($project_info != null) {
                            $project_info_id = $project_info->GetId();
                            $db->execSqlUpdate("UPDATE content SET project_info = '$project_info_id' WHERE content_id = '$this->id'", FALSE);
                        }
                    } else {
                        $project_info = self :: getObject($this->content_row['project_info']);
                        $name = "content_" . $this->id . "_project_info_erase";
                        if (!empty ($_REQUEST[$name]) && $_REQUEST[$name] == true) {
                            $db->execSqlUpdate("UPDATE content SET project_info = NULL WHERE content_id = '$this->id'", FALSE);
                            $project_info->delete($errmsg);
                        } else {
                            $project_info->processAdminUI();
                        }
                    }
                } //End content metadata

                if ($this->isSimpleContent() == false || $this->isPersistent()) {
                    /* is_persistent */
                    $name = "content_" . $this->id . "_is_persistent";
                    !empty ($_REQUEST[$name]) ? $this->setIsPersistent(true) : $this->setIsPersistent(false);

                    /* content_has_owners */
                    $sql = "SELECT * FROM content_has_owners WHERE content_id='$this->id'";
                    $db->execSql($sql, $content_owner_rows, false);
                    if ($content_owner_rows != null) {
                        foreach ($content_owner_rows as $content_owner_row) {
                            $user = User :: getObject($content_owner_row['user_id']);
                            $user_id = $user->getId();
                            $name = "content_" . $this->id . "_owner_" . $user->GetId() . "_remove";
                            if (!empty ($_REQUEST[$name])) {
                                $this->deleteOwner($user);
                            } else {
                                $name = "content_" . $this->id . "_owner_" . $user->GetId() . "_is_author";
                                $content_owner_row['is_author'] == 't' ? $is_author = true : $is_author = false;
                                !empty ($_REQUEST[$name]) ? $should_be_author = true : $should_be_author = false;
                                if ($is_author != $should_be_author) {
                                    $should_be_author ? $is_author_sql = 'TRUE' : $is_author_sql = 'FALSE';
                                    $sql = "UPDATE content_has_owners SET is_author=$is_author_sql WHERE content_id='$this->id' AND user_id='$user_id'";

                                    if (!$db->execSqlUpdate($sql, false)) {
                                        throw new Exception(_('Unable to set as author in the database.'));
                                    }

                                }

                            }
                        }
                    }
                    $user = User :: processSelectUserUI("content_{$this->id}_new_owner");
                    $name = "content_{$this->id}_add_owner_submit";
                    if (!empty ($_REQUEST[$name]) && $user != null) {
                        $this->addOwner($user);
                    }
                }
            }
            $this->refresh();
        }
    }

    /**
     * Tell if a given user is already subscribed to this content
     * @param User the given user
     * @return boolean
     */
    public function isUserSubscribed(User $user) {
        $db = AbstractDb::getObject();
        $sql = "SELECT content_id FROM user_has_content WHERE user_id = '{$user->getId()}' AND content_id = '{$this->getId()}';";
        $db->execSqlUniqueRes($sql, $row, false);

        if ($row)
            return true;
        else
            return false;
    }

    /** Subscribe to the project
     * @return true on success, false on failure */
    public function subscribe(User $user) {
        return $user->addContent($this);
    }
    /** Unsubscribe to the project
     * @return true on success, false on failure */
    public function unsubscribe(User $user) {
        return $user->removeContent($this);
    }

    /** If the title is not empty, should it be displayed?
    * @return true or false */
    public function titleShouldDisplay() {
        if ($this->content_row['title_is_displayed'] == 't') {
            $retval = true;
        } else {
            $retval = false;
        }
        return $retval;
    }

    /** If the title is not empty, should it be displayed?
     * @param $should_display true or false
     * */
    public function setTitleIsDisplayed($should_display) {
        if ($should_display != $this->titleShouldDisplay()) /* Only update database if there is an actual change */ {
            $should_display ? $should_display_sql = 'TRUE' : $should_display_sql = 'FALSE';
            $db = AbstractDb::getObject();
            $db->execSqlUpdate("UPDATE content SET title_is_displayed = $should_display_sql WHERE content_id = '$this->id'", false);
            $this->refresh();
        }

    }

    /** Persistent (or read-only) content is meant for re-use.  It will not be deleted when the delete() method is called.  When a containing element (ContentGroup, ContentGroupElement) is deleted, it calls delete on all the content it includes.  If the content is persistent, only the association will be removed.
    * @return true or false */
    public function isPersistent() {
        if ($this->content_row['is_persistent'] == 't') {
            $retval = true;
        } else {
            $retval = false;
        }
        return $retval;
    }

    /** Set if the content group is persistent
     * @param $is_locative_content true or false
     * */
    public function setIsPersistent($is_persistent) {
        if ($is_persistent != $this->isPersistent()) /* Only update database if there is an actual change */ {
            $is_persistent ? $is_persistent_sql = 'TRUE' : $is_persistent_sql = 'FALSE';

            $db = AbstractDb::getObject();
            $db->execSqlUpdate("UPDATE content SET is_persistent = $is_persistent_sql WHERE content_id = '$this->id'", false);
            $this->refresh();
        }

    }

    /** Reloads the object from the database.  Should normally be called after a set operation.
     * This function is private because calling it from a subclass will call the
     * constructor from the wrong scope */
    private function refresh() {
        $this->__construct($this->id);
    }

    /**
     * @see GenericObject
     * @internal Persistent content will not be deleted
     */
    public function delete(& $errmsg) {
        $retval = false;
        if ($this->isPersistent()) {
            $errmsg = _("Content is persistent (you must make it non persistent before you can delete it)");
        } else {
            $db = AbstractDb::getObject();
            if ($this->isOwner(User :: getCurrentUser()) || User :: getCurrentUser()->isSuperAdmin()) {
                $sql = "DELETE FROM content WHERE content_id='$this->id'";
                $db->execSqlUpdate($sql, false);
                $retval = true;
            } else {
                $errmsg = _("Access denied (not owner of content)");
            }
        }
        return $retval;
    }

} // End class

/* This allows the class to enumerate it's children properly */
$class_names = Content :: getAvailableContentTypes();

foreach ($class_names as $class_name) {
    /**
     * Load requested content class
     */
    require_once ('classes/Content/' . $class_name . '/' . $class_name . '.php');
}

/*
 * Local variables:
 * tab-width: 4
 * c-basic-offset: 4
 * c-hanging-comment-ender-p: nil
 * End:
 */