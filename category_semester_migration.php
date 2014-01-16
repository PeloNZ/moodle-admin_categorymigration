<?php
/**
 * This script migrates categories and their child courses to new categories
 *
 * @package    admin
 * @subpackage cli
 * @copyright  2012 Catalyst IT
 * @author     Chris Wharton <chrisw@catalyst.net.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(dirname(dirname(dirname(__FILE__))).'/config.php');
require_once($CFG->libdir.'/adminlib.php');       // various admin-only functions
require_once($CFG->libdir.'/upgradelib.php');     // general upgrade/install related functions
require_once($CFG->libdir.'/clilib.php');         // cli only functions
require_once($CFG->libdir.'/environmentlib.php');
require_once($CFG->libdir.'/pluginlib.php');
require_once($CFG->libdir.'/filelib.php');
require_once($CFG->libdir.'/moodlelib.php');
require_once($CFG->libdir.'/enrollib.php');
require_once($CFG->dirroot.'/course/lib.php');

// now get cli options
list($options, $unrecognized) = cli_get_params(
        array(
            'newyear'          => false,
            'currentyear'      => false,
            'reservelist'      => false,
            'reservecat'       => false,
            'help'              => false
            ),
        array(
            'h' => 'help'
            )
        );

if ($unrecognized) {
    $unrecognized = implode("\n  ", $unrecognized);
    cli_error(get_string('cliunknowoption', 'admin', $unrecognized));
}

if ($options['help']) {
    $help =
        "Move course categories and their child categories and courses to a new
        category tree.
        Please note you must execute this script with the same uid as apache!

        Options:
        --newyear            The new year; copies current courses to a sub cat with this title and resets them
        --currentyear        The current year; moves current courses to a sub cat with this title
        --reservelist        The list of courses to except from this migration
        --reservecat         The list of courses to except from this migration

        -h, --help            Print out this help

        Example:
        \$sudo -u www-data /usr/bin/php admin/cli/category_migration.php
        ";

    echo $help;
    die;
}

// parse the cli parameters and add create relevant variables
extract($options);

// first we deal with the reserve courses
if ($reservelist AND $reservecat) {
    echo "moving reserved courses\n";
    if ($reservelist = file($CFG->dirroot .'/'. $reservelist, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) {
        // clean up the list of course names - TODO filter for xss
        $reservelist = array_map('trim', $reservelist);
        // get the corresponding course ids from the list
        $reservecourses = $DB->get_records_list('course', 'fullname', $reservelist, 'sortorder DESC', 'id');
        // get the category that the courses will be moved to
        $reservecat = $DB->get_record('course_categories', array('name'=>$reservecat), '*', MUST_EXIST);
        // change to an array of course ids
        $reservecourses = array_map(create_function('$o', 'return $o->id;'), $reservecourses);

        move_courses($reservecourses, $reservecat->id);
    }
    echo "reserved courses moved ok\n";
}
// get a list of all top level categories, excluding the reservecat
$parentcats = get_child_categories(0);

// add a current year category to each top level category
$newcategory = new stdClass();

foreach ($parentcats as $parent) {
    // get the child categories of each parent
    $subcats = get_child_categories($parent->id);
    // create the current year category
    $newcategory->parent = $parent->id;
    $newcategory->name = $currentyear;
    echo "creating category {$newcategory->name} in {$parent->name}\n";
    $currentyearcat = create_course_category($newcategory);
    fix_course_sortorder();

    // create the new year category
    $newcategory->name = $newyear;
    echo "creating category {$newcategory->name} in {$parent->name}\n";
    $newyearcat = create_course_category($newcategory);
    fix_course_sortorder();

    // move the children and their contents into the current year category
    foreach ($subcats as $child) {
        echo "moving category {$child->name} to {$parent->name} - {$currentyearcat->name}\n";
        if ($child->id != $reservecat->id) {
            move_category($child, $currentyearcat);

            // copy subcats categories to new year parents
            $newchild = clone $child;
            $newchild->id = null;
            $newchild->parent = $newyearcat->id;
            echo "creating category {$newchild->name} in {$parent->name} - {$newyearcat->name}\n";
            $newchild = create_course_category($newchild);
            fix_course_sortorder();

            // get courses in this category
            $courses = $DB->get_records('course', array('category'=>$child->id), 'sortorder DESC');
            // build a new copy of each course
            foreach ($courses as $course) {
                $newcourse = clone $course;
                $newcourse->id = null;
                $newcourse->category = $newchild->id;

                update_course_info($newcourse, $currentyearcat->name, $newyearcat->name);
                echo "creating course {$newcourse->shortname} in {$parent->name} - {$newyearcat->name}\n";
                create_course($newcourse);
                /*
                // restore course data and enrolments
                echo "importing course data from {$course->shortname} in to {$newcourse->shortname}\n";
                import_course($newcourse, $course);
                // now enrol the teachers into the new courses
                $oldcontext = context_course::instance($course->id);
                // get enroled users, excluding students, in old course
                $users = get_enrolled_users($oldcontext);
                $sql = "
                SELECT ra.userid, ra.roleid
                FROM mdl_role r JOIN mdl_role_assignments ra
                ON r.id = ra.roleid
                WHERE r.shortname NOT IN ('student','parent','guest') AND ra.contextid = ?
                ";
                $params = array($oldcontext->id);
                $users = $DB->execute($sql, $params);

                // get the enrol instance for new course
                $instance = $DB->get_record('enrol', array('courseid'=>$newcourse->id, 'enrol'=>'manual'));

                // enrol each user in the new course
                foreach ($users as $user) {
                echo "enroling user {$user->id} in {$newcourse->shortname}\n";
                enrol_user($instance, $user->userid, $user->roleid, time());
                }
                 */
            }
        }
    }
}

echo "Course migration complete\n";
exit(0);

// can't have duplicate shortnames or idnumbers. some also have the year already in there
function update_course_info(&$courseobj, $search, $replace) {
    // only some of the properties matter
    $properties = array(
            'fullname',
            'shortname',
            'idnumber',
            );
    foreach ($properties as $property) {
        $from = $courseobj->$property;
        $name = $courseobj->fullname;
        if (empty($courseobj->$property)) { // site id numbers may be empty
            continue;
        } else if (strpos($courseobj->$property, $replace)) { // some next year courses may exist already
            continue;
        } else if (strpos($courseobj->$property, $search) !== false) { // replace old year with new year
            $courseobj->$property = str_replace($search, $replace, $courseobj->$property);
        } else { // or append the new year
            $courseobj->$property = $courseobj->$property . " {$replace}";
        }
        echo "updating {$property} in Course: \"{$name}\" from \"{$from}\" to \"{$courseobj->$property}\"\n";
    }
    // update the start date of the course
    $courseobj->startdate = make_timestamp($replace);
}

function import_course($course, $importcourse) {
    global $CFG, $DB;

    // Require both the backup and restore libs
    require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
    require_once($CFG->dirroot . '/backup/moodle2/backup_plan_builder.class.php');
    require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
    require_once($CFG->dirroot . '/backup/util/ui/import_extensions.php');

    // The courseid we are importing to
    $courseid = $course->id;
    // The id of the course we are importing FROM (will only be set if past first stage
    $importcourseid = $importcourse->id;
    // The target method for the restore (adding or deleting)
    $restoretarget = backup::TARGET_CURRENT_ADDING;

    // Load the course and context
    $context = get_context_instance(CONTEXT_COURSE, $courseid);

    // Load the course +context to import from
    $importcontext = get_context_instance(CONTEXT_COURSE, $importcourseid);

    // Attempt to load the existing backup controller (backupid will be false if there isn't one)
    $backupid = false; //optional_param('backup', false, PARAM_ALPHANUM);
    if (!($bc = backup_ui::load_controller($backupid))) {
        $bc = new backup_controller(backup::TYPE_1COURSE, $importcourse->id, backup::FORMAT_MOODLE,
                backup::INTERACTIVE_YES, backup::MODE_IMPORT, $USER->id);
        $bc->get_plan()->get_setting('users')->set_status(backup_setting::LOCKED_BY_CONFIG);
        $settings = $bc->get_plan()->get_settings();

        // For the initial stage we want to hide all locked settings and if there are
        // no visible settings move to the next stage
        $visiblesettings = true;
        import_ui::skip_current_stage(!$visiblesettings);
    }

    // Process the current stage
    $backup->process();

    // If it's the final stage process the import
    if ($backup->get_stage() == backup_ui::STAGE_FINAL) {
        // First execute the backup
        $backup->execute();
        $backup->destroy();
        unset($backup);

        // Check whether the backup directory still exists. If missing, something
        // went really wrong in backup, throw error. Note that backup::MODE_IMPORT
        // backups don't store resulting files ever
        $tempdestination = $CFG->tempdir . '/backup/' . $backupid;
        if (!file_exists($tempdestination) || !is_dir($tempdestination)) {
            cli_error('unknownbackupexporterror'); // shouldn't happen ever
        }

        // Prepare the restore controller. We don't need a UI here as we will just use what
        // ever the restore has (the user has just chosen).
        $rc = new restore_controller($backupid, $course->id, backup::INTERACTIVE_YES, backup::MODE_IMPORT, $USER->id, $restoretarget);
        // Convert the backup if required.... it should NEVER happed
        if ($rc->get_status() == backup::STATUS_REQUIRE_CONV) {
            $rc->convert();
        }
        // Mark the UI finished.
        $rc->finish_ui();
        // Execute prechecks
        if (!$rc->execute_precheck()) {
            $precheckresults = $rc->get_precheck_results();
            if (is_array($precheckresults) && !empty($precheckresults['errors'])) {
                fulldelete($tempdestination);

                die();
            }
        } else {
            if ($restoretarget == backup::TARGET_CURRENT_DELETING || $restoretarget == backup::TARGET_EXISTING_DELETING) {
                restore_dbops::delete_course_content($course->id);
            }
            // Execute the restore
            $rc->execute_plan();
        }

        // Delete the temp directory now
        fulldelete($tempdestination);

        // Display a notification and a continue button
        echo "success";

        die();

    } else {
        // Otherwise save the controller and progress
        $backup->save_controller();
    }

    $backup->destroy();
    unset($backup);
}
?>
