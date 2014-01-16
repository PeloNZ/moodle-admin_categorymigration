<?php
/**
 * This script migrates categories and their child courses to new categories
 * THIS IS A RUN-ONCE SCRIPT! Back up and test before using.
 *
 * @package    admin
 * @subpackage cli
 * @copyright  2012 Catalyst IT
 * @author     Chris Wharton <chrisw@catalyst.net.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(dirname(dirname(dirname(__FILE__))).'/config.php');
require_once($CFG->libdir.'/clilib.php');         // cli only functions
require_once($CFG->libdir.'/coursecatlib.php');
require_once($CFG->dirroot.'/course/lib.php');
require_once($CFG->dirroot.'/course/externallib.php');
require_once($CFG->dirroot.'/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot.'/backup/util/dbops/restore_dbops.class.php');

$USER = $DB->get_record('user', array('id'=>2));

$help =
"Move course categories and their child categories and courses to a new
category tree.
Please note you must execute this script with the same uid as apache!

Options:
--newyear            The new year; copies current courses to a sub cat with this title and resets them
--currentyear        The current year; moves current courses to a sub cat with this title
--reservelist        The list of courses to except from this migration
--reservecat         The category to move reserved courses into

-h, --help            Print out this help

Example:
\$sudo -u www-data /usr/bin/php admin/cli/category_migration.php --newyear=2013 --currentyear=2012 --reservecat=IB --reservelist=ib_course_list.txt
";


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
    echo $help;
    die;
}

if (empty($options['newyear']) OR empty($options['currentyear'])) {
    echo $help;
    die;
}

// parse the cli parameters and add create relevant variables
extract($options);

echo strftime('%c') . " : Begin\n";

// first deal with the reserve courses
if ($reservelist AND $reservecat) {
    echo "moving reserved courses\n";
    if ($reservelist = file($CFG->dirroot .'/'. $reservelist, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) {
        // clean up the list of course names
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
$rootcat = coursecat::get(0);
$parentcats = $rootcat->get_children();

// begin the migration
foreach ($parentcats as $parent) {
    // get the child categories of each parent
    $yearcats = $parent->get_children();

    // build the data for the new category
    $newcategory = new stdClass();
    $newcategory->parent = $parent->__get('id');
    $newcategory->name = $newyear;

    echo "creating category {$newcategory->name} in {$parent->name}\n";
    $newyearcat = coursecat::create($newcategory);

    foreach ($yearcats as $child) { // this is the year category
        // only copy yearcats from the currentyear parent
        if ($child->name != $currentyear) {
            continue;
        }

        // the reserved category must be ignored
        if ($child->id == $reservecat->id) {
            continue;
        }

        // check if there are any courses at this level, ie not in a deeper category
        copy_courses($child, $newyearcat, $parent, $currentyear, $newyear);

        // then go deeper and get the subcats of that year
        $subjectcats = $child->get_children();
        foreach ($subjectcats as $subject) {
            // copy yearcats categories to new year parents
            $newchild = new stdClass();
            $newchild->id = null;
            $newchild->parent = $newyearcat->id;
            $newchild->name = $subject->__get('name');

            echo "creating category {$newchild->name} in {$parent->name} / {$newyearcat->name}\n";
            $newchild = coursecat::create($newchild);

            // get courses in this category
            copy_courses($subject, $newchild, $subject, $currentyear, $newyear);
        }
    }
}

echo strftime('%c') . " : Course migration complete\n";
exit(0);

/**
 * filter course properties
 *
 * @param object &$courseobj the course being filtered, by reference
 * @param str    $search     look for this string
 * @param str    $replace    replace with this string
 * @return void
 */
function update_course_info(&$courseobj, $search, $replace) {
    // only some of the properties matter
    // can't have duplicate shortnames or idnumbers. some also have the year already in there
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

/**
 * Enrol user into course
 * Stripped function from lib/enrollib.php
 *
 * @param stdClass $instance
 * @param int $userid
 * @param int $roleid optional role id
 * @param int $timestart 0 means unknown
 * @param int $timeend 0 means forever
 * @param int $status default to ENROL_USER_ACTIVE for new enrolments, no change by default in updates
 * @return void
 */
function enrol_user(stdClass $instance, $userid, $roleid = NULL, $timestart = 0, $timeend = 0, $status = NULL) {
    global $DB, $USER, $CFG; // CFG necessary!!!

    $name = 'manual';
    $courseid = $instance->courseid;

    $context = get_context_instance(CONTEXT_COURSE, $instance->courseid, MUST_EXIST);

    // enrol
    $ue = new stdClass();
    $ue->enrolid      = $instance->id;
    $ue->status       = is_null($status) ? ENROL_USER_ACTIVE : $status;
    $ue->userid       = $userid;
    $ue->timestart    = $timestart;
    $ue->timeend      = $timeend;
    $ue->modifierid   = $USER->id;
    $ue->timecreated  = time();
    $ue->timemodified = $ue->timecreated;
    $ue->id = $DB->insert_record('user_enrolments', $ue);

    // add extra info and trigger event
    $ue->courseid  = $courseid;
    $ue->enrol     = $name;
    events_trigger('user_enrolled', $ue);

    // this must be done after the enrolment event so that the role_assigned event is triggered afterwards
    role_assign($roleid, $userid, $context->id, 'enrol_'.$name, $instance->id);
}

/**
 * Copy a course, its content, and enrolments into a new category
 */
function copy_courses($currentcategory, $subcategory, $parentcategory, $currentyear, $newyear) {
    global $DB;

    $failedcourses = array();
    $courses = $DB->get_recordset('course', array('category'=>$currentcategory->id), 'sortorder DESC');

    foreach ($courses as $course) {
        // build a new copy of each course
        $newcourse = clone $course;
        $newcourse->id = null;
        $newcourse->category = $subcategory->id;
        $newcourse->visible = 1;

        // change the course details for the new year
        update_course_info($newcourse, $currentyear, $newyear);

        list($ignoreme, $newcourse->shortname) = restore_dbops::calculate_course_names(0, 'n/a', $newcourse->shortname);

        echo "creating course {$newcourse->shortname} in {$parentcategory->name} / {$subcategory->name} / {$newyear}\n";

        try {
            $newcourse = core_course_external::duplicate_course( // this transforms $newcourse into an array
                    $course->id,
                    $newcourse->fullname,
                    $newcourse->shortname,
                    $newcourse->category,
                    0,
                    array(array('name' => 'users', 'value' => false))
                    );
        } catch (Exception $e) {
            echo "ERROR: Failed duplicating course {$course->id}\n";
            var_dump($e);
            $failedcourses[] = $course->id;
            continue;
        }
        // rapidly get course context
        $sql = "
            SELECT *
            FROM mdl_context
            WHERE instanceid = ? AND contextlevel = ?
            ";
        $oldcontext = $DB->get_record_sql($sql, array($course->id, '50'));

        // get the enrol instance for new course
        $instance = $DB->get_record('enrol', array('courseid'=>$newcourse['id'], 'enrol'=>'manual'));

        // get all trainer type users in the old course
        $sql = "
            SELECT ra.userid, ra.roleid
            FROM mdl_role r JOIN mdl_role_assignments ra
            ON r.id = ra.roleid
            WHERE r.shortname NOT IN ('student','parent','guest') AND ra.contextid = ?
            ";
        $users = $DB->get_recordset_sql($sql, array($oldcontext->id));

        // enrol each user in the new course
        foreach ($users as $user) {
            echo "enroling user {$user->userid} in {$newcourse['shortname']}\n";
            enrol_user($instance, $user->userid, $user->roleid, time());
        }
        $users->close(); // close the recordset

        // report errors
        if (count($failedcourses)) {
            echo "ERROR: Failed copying the following courses:\n";
            var_dump($failedcourses);
        } else {
            echo "No failed courses.";
        }
    }
    $courses->close(); // close the recordset
}

?>
