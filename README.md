copyright  2012, 2013 Catalyst IT
author     Chris Wharton <chrisw@catalyst.net.nz>
license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later

Moodle Category and Course migration tool. Requires Moodle 2.5 or later.

This tool is designed to make setting up courses for a new school year easy.
It is specific to a certain category hierarchy, but can of course be customised
for more complex hierarchies.

Courses with the current year in their name will have their shortname and longname updated to use the new year.

Move course categories and their child categories and courses to a new
category tree.
Please note you must execute this script with the same uid as apache!

Options:
--newyear            The new year; copies current courses to a sub cat with this title and resets them.
--currentyear        The current year; specifies which category to copy courses from.
--reservelist        The list of courses to except from this migration.
--reservecat         The category to move reserved courses into.

-h, --help            Print out this help

Example:
$ sudo -u www-data /usr/bin/php admin/cli/category_migration.php --newyear=2014 --currentyear=2013 --reservecat=IB --reservelist=ib_course_list.txt

The reservelist of courses expects a line separated list of Moodle course full names, in a text file.
This is useful for courses that aren't specific to an exact year, that might run over two years for example.

Installation and usage:
Copy category_semester_migration.php to admin/cli/.
Run the script on a test instance, and make sure everything is correct.
Backup everything.
Make sure you have enough space in moodledata to duplicate all your course content.
Run the script on production.

What gets created?
- A sub category with the new year as the title (eg. 2014), in each top level category.
- The courses from the current year category are copied into the new year category, along with:
- Course content (eg. assignments, quizzes, forums, attached documents).
- Teacher enrolments.

What gets left out?
- User content in courses (eg. forum posts, assignment submissions, quiz answers).
- Student enrolments.

Example expected hierarchy:

Site
|
|-Category 1
|    |- Year 1
|    |    |-Subject Category
|    |        |- Course 1
|    |        |- Course 2
|    |- Year 2
|        |-Subject Category
|        |    |- Course 1
|        |    |- Course 2
|        |-Subject Category
|        |     |- Course 3
|        |-Course 4 (course at year level)
|-Category 2
|    |- Year 1
|    |    |-Subject Category
|    |        |- Course 1
|    |        |- Course 2
|    |- Year 2
|        |-Subject Category
|            |- Course 1
|            |- Course 2
