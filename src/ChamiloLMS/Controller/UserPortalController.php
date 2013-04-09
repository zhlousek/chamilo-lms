<?php
namespace ChamiloLMS\Controller;

use Silex\Application;
use \ChamiloSession as Session;
use Symfony\Component\HttpFoundation\Response;

class UserPortalController
{
    /**
     * @param Application $app
     * @param string $filter for the userportal courses page. Only works when setting 'history'
     * @return Response|void
     */
    function indexAction(Application $app, $filter = null)
    {
        //@todo Use filters like "after/before|finish" to manage user access
        api_block_anonymous_users();

        //Abort request because the user is not allowed here - @todo use filters
        if ($app['allowed'] == false) {
            return $app->abort(403);
        }

        // Check if a user is enrolled only in one course for going directly to the course after the login.
        if (api_get_setting('go_to_course_after_login') == 'true') {
            $this->redirectAfterLogin();
        }

        // Main courses and session list
        $coursesAndSessions = \PageController::return_courses_and_sessions(api_get_user_id(), $filter);

        //Show the chamilo mascot
        if (empty($coursesAndSessions) && empty($filter)) {
            \PageController::return_welcome_to_course_block($app['template']);
        }

        $app['template']->assign('content', $coursesAndSessions);

        \PageController::return_profile_block();
        \PageController::return_user_image_block();
        \PageController::return_course_block($filter);

        $app['template']->assign('navigation_course_links', $app['template']->returnNavigationLinks());
        \PageController::return_reservation_block();
        $app['template']->assign('search_block', \PageController::return_search_block());
        $app['template']->assign('classes_block', \PageController::return_classes_block());
        \PageController::return_skills_links();

        // Deleting the session_id.
        Session::erase('session_id');

        $response = $app['template']->render_template('userportal/index.tpl');

        //return new Response($response, 200, array('Cache-Control' => 's-maxage=3600, private'));
        return new Response($response, 200, array());
    }

    function redirectAfterLogin()
    {
        // Get the courses list
        $personal_course_list = \UserManager::get_personal_session_course_list(api_get_user_id());

        $my_session_list = array();
        $count_of_courses_no_sessions = 0;
        $count_of_courses_with_sessions = 0;

        foreach ($personal_course_list as $course) {
            if (!empty($course['id_session'])) {
                $my_session_list[$course['id_session']] = true;
                $count_of_courses_with_sessions++;
            } else {
                $count_of_courses_no_sessions++;
            }
        }
        $count_of_sessions = count($my_session_list);

        if ($count_of_sessions == 1 && $count_of_courses_no_sessions == 0) {

            $key = array_keys($personal_course_list);
            $course_info = $personal_course_list[$key[0]];
            $id_session = isset($course_info['id_session']) ? $course_info['id_session'] : 0;

            $url = api_get_path(WEB_CODE_PATH).'session/?session_id='.$id_session;
            header('location:'.$url);
            exit;
        }

        if (!isset($_SESSION['coursesAlreadyVisited']) && $count_of_sessions == 0 && $count_of_courses_no_sessions == 1) {
            $key = array_keys($personal_course_list);
            $course_info = $personal_course_list[$key[0]];
            $course_directory = $course_info['course_info']['path'];
            $id_session = isset($course_info['id_session']) ? $course_info['id_session'] : 0;

            $url = api_get_path(WEB_COURSE_PATH).$course_directory.'/?id_session='.$id_session;
            header('location:'.$url);
            exit;
        }
    }

    function check_last_login()
    {
        /**
         * @todo This piece of code should probably move to local.inc.php where the actual login procedure is handled.
         * @todo Check if this code is used. I think this code is never executed because after clicking the submit button
         *       the code does the stuff in local.inc.php and then redirects to index.php or user_portal.php depending
         *       on api_get_setting('page_after_login').
         */
        if (!empty($_POST['submitAuth'])) {
            // The user has been already authenticated, we are now to find the last login of the user.
            if (!empty($this->user_id)) {
                $track_login_table = Database :: get_statistic_table(TABLE_STATISTIC_TRACK_E_LOGIN);
                $sql_last_login = "SELECT login_date
                                    FROM $track_login_table
                                    WHERE login_user_id = '".$this->user_id."'
                                    ORDER BY login_date DESC LIMIT 1";
                $result_last_login = Database::query($sql_last_login);
                if (!$result_last_login) {
                    if (Database::num_rows($result_last_login) > 0) {
                        $user_last_login_datetime = Database::fetch_array($result_last_login);
                        $user_last_login_datetime = $user_last_login_datetime[0];
                        Session::write('user_last_login_datetime', $user_last_login_datetime);
                    }
                }
                Database::free_result($result_last_login);

                if (api_is_platform_admin()) {
                    // decode all open event informations and fill the track_c_* tables
                    include api_get_path(LIBRARY_PATH).'stats.lib.inc.php';
                    decodeOpenInfos();
                }
            }
            // End login -- if ($_POST['submitAuth'])
        } else {
            // Only if login form was not sent because if the form is sent the user was already on the page.
            event_open();
        }
    }


    /**
     * Reacts on a failed login:
     * Displays an explanation with a link to the registration form.
     *
     * @deprecated use twig template to prompt errors
     */
    function handle_login_failed()
    {
        $message = get_lang('InvalidId');

        if (!isset($_GET['error'])) {
            if (api_is_self_registration_allowed()) {
                $message = get_lang('InvalidForSelfRegistration');
            }
        } else {
            switch ($_GET['error']) {
                case '':
                    if (api_is_self_registration_allowed()) {
                        $message = get_lang('InvalidForSelfRegistration');
                    }
                    break;
                case 'account_expired':
                    $message = get_lang('AccountExpired');
                    break;
                case 'account_inactive':
                    $message = get_lang('AccountInactive');
                    break;
                case 'user_password_incorrect':
                    $message = get_lang('InvalidId');
                    break;
                case 'access_url_inactive':
                    $message = get_lang('AccountURLInactive');
                    break;
                case 'unrecognize_sso_origin':
                    //$message = get_lang('SSOError');
                    break;
            }
        }
        return Display::return_message($message, 'error');
    }
}