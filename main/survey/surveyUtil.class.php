<?php
/* For licensing terms, see /license.txt */

use Chamilo\CourseBundle\Entity\CSurvey;
use ChamiloSession as Session;

/**
 * This class offers a series of general utility functions for survey querying and display
 * @package chamilo.survey
 */
class SurveyUtil
{
    /**
     * Checks whether the given survey has a pagebreak question as the first
     * or the last question.
     * If so, break the current process, displaying an error message
     * @param    integer $survey_id Survey ID (database ID)
     * @param    boolean $continue Optional. Whether to continue the current
     * process or exit when breaking condition found. Defaults to true (do not break).
     * @return    void
     */
    public static function check_first_last_question($survey_id, $continue = true)
    {
        // Table definitions
        $tbl_survey_question = Database::get_course_table(TABLE_SURVEY_QUESTION);
        $course_id = api_get_course_int_id();

        // Getting the information of the question
        $sql = "SELECT * FROM $tbl_survey_question
                WHERE c_id = $course_id AND survey_id='".Database::escape_string($survey_id)."'
                ORDER BY sort ASC";
        $result = Database::query($sql);
        $total = Database::num_rows($result);
        $counter = 1;
        $error = false;
        while ($row = Database::fetch_array($result, 'ASSOC')) {
            if ($counter == 1 && $row['type'] == 'pagebreak') {
                echo Display::return_message(get_lang('PagebreakNotFirst'), 'error', false);
                $error = true;
            }
            if ($counter == $total && $row['type'] == 'pagebreak') {
                echo Display::return_message(get_lang('PagebreakNotLast'), 'error', false);
                $error = true;
            }
            $counter++;
        }

        if (!$continue && $error) {
            Display::display_footer();
            exit;
        }
    }

    /**
     * This function removes an (or multiple) answer(s) of a user on a question of a survey
     *
     * @param mixed   The user id or email of the person who fills the survey
     * @param integer The survey id
     * @param integer The question id
     * @param integer The option id
     *
     * @author Patrick Cool <patrick.cool@UGent.be>, Ghent University
     * @version January 2007
     */
    public static function remove_answer($user, $survey_id, $question_id, $course_id)
    {
        $course_id = intval($course_id);
        // table definition
        $table_survey_answer = Database::get_course_table(TABLE_SURVEY_ANSWER);
        $sql = "DELETE FROM $table_survey_answer
				WHERE
				    c_id = $course_id AND
                    user = '".Database::escape_string($user)."' AND
                    survey_id = '".intval($survey_id)."' AND
                    question_id = '".intval($question_id)."'";
        Database::query($sql);
    }

    /**
     * This function stores an answer of a user on a question of a survey
     *
     * @param mixed   The user id or email of the person who fills the survey
     * @param integer Survey id
     * @param integer Question id
     * @param integer Option id
     * @param string  Option value
     * @param array $survey_data Survey data settings
     * @return bool False if insufficient data, true otherwise
     *
     * @author Patrick Cool <patrick.cool@UGent.be>, Ghent University
     * @version January 2007
     */
    public static function store_answer(
        $user,
        $survey_id,
        $question_id,
        $option_id,
        $option_value,
        $survey_data
    ) {
        // If the question_id is empty, don't store an answer
        if (empty($question_id)) {
            return false;
        }
        // Table definition
        $table_survey_answer = Database::get_course_table(TABLE_SURVEY_ANSWER);

        // Make the survey anonymous
        if ($survey_data['anonymous'] == 1) {
            $surveyUser = Session::read('surveyuser');
            if (empty($surveyUser)) {
                $user = md5($user.time());
                Session::write('surveyuser', $user);
            } else {
                $user = Session::read('surveyuser');
            }
        }

        $course_id = $survey_data['c_id'];

        $sql = "INSERT INTO $table_survey_answer (c_id, user, survey_id, question_id, option_id, value) VALUES (
				$course_id,
				'".Database::escape_string($user)."',
				'".Database::escape_string($survey_id)."',
				'".Database::escape_string($question_id)."',
				'".Database::escape_string($option_id)."',
				'".Database::escape_string($option_value)."'
				)";
        Database::query($sql);
        $insertId = Database::insert_id();

        $sql = "UPDATE $table_survey_answer SET answer_id = $insertId 
                WHERE iid = $insertId";
        Database::query($sql);

        return true;
    }

    /**
     * This function checks the parameters that are used in this page
     *
     * @return string $people_filled The header, an error and the footer if any parameter fails, else it returns true
     * @author Patrick Cool <patrick.cool@UGent.be>, Ghent University
     * @version February 2007
     */
    public static function check_parameters($people_filled)
    {
        $error = false;

        // Getting the survey data
        $survey_data = SurveyManager::get_survey($_GET['survey_id']);

        // $_GET['survey_id'] has to be numeric
        if (!is_numeric($_GET['survey_id'])) {
            $error = get_lang('IllegalSurveyId');
        }

        // $_GET['action']
        $allowed_actions = array(
            'overview',
            'questionreport',
            'userreport',
            'comparativereport',
            'completereport',
            'deleteuserreport'
        );
        if (isset($_GET['action']) && !in_array($_GET['action'], $allowed_actions)) {
            $error = get_lang('ActionNotAllowed');
        }

        // User report
        if (isset($_GET['action']) && $_GET['action'] == 'userreport') {
            if ($survey_data['anonymous'] == 0) {
                foreach ($people_filled as $key => & $value) {
                    $people_filled_userids[] = $value['invited_user'];
                }
            } else {
                $people_filled_userids = $people_filled;
            }

            if (isset($_GET['user']) && !in_array($_GET['user'], $people_filled_userids)) {
                $error = get_lang('UnknowUser');
            }
        }

        // Question report
        if (isset($_GET['action']) && $_GET['action'] == 'questionreport') {
            if (isset($_GET['question']) && !is_numeric($_GET['question'])) {
                $error = get_lang('UnknowQuestion');
            }
        }

        if ($error) {
            $tool_name = get_lang('Reporting');
            Display::addFlash(
                Display::return_message(
                    get_lang('Error').': '.$error,
                    'error',
                    false
                )
            );
            Display::display_header($tool_name);
            Display::display_footer();
            exit;
        } else {
            return true;
        }
    }

    /**
     * This function deals with the action handling
     * @param array $survey_data
     * @param array $people_filled
     * @return void
     * @author Patrick Cool <patrick.cool@UGent.be>, Ghent University
     * @version February 2007
     */
    public static function handle_reporting_actions($survey_data, $people_filled)
    {
        $action = isset($_GET['action']) ? $_GET['action'] : null;

        // Getting the number of question
        $temp_questions_data = SurveyManager::get_questions($_GET['survey_id']);

        // Sorting like they should be displayed and removing the non-answer question types (comment and pagebreak)
        $my_temp_questions_data = $temp_questions_data == null ? array() : $temp_questions_data;
        $questions_data = array();

        foreach ($my_temp_questions_data as $key => & $value) {
            if ($value['type'] != 'comment' && $value['type'] != 'pagebreak') {
                $questions_data[$value['sort']] = $value;
            }
        }

        // Counting the number of questions that are relevant for the reporting
        $survey_data['number_of_questions'] = count($questions_data);

        if ($action == 'questionreport') {
            self::display_question_report($survey_data);
        }
        if ($action == 'userreport') {
            self::display_user_report($people_filled, $survey_data);
        }
        if ($action == 'comparativereport') {
            self::display_comparative_report();
        }
        if ($action == 'completereport') {
            self::display_complete_report($survey_data);
        }
        if ($action == 'deleteuserreport') {
            self::delete_user_report($_GET['survey_id'], $_GET['user']);
        }
    }

    /**
     * This function deletes the report of an user who wants to retake the survey
     * @param integer $survey_id
     * @param integer $user_id
     * @return void
     * @author Christian Fasanando Flores <christian.fasanando@dokeos.com>
     * @version November 2008
     */
    public static function delete_user_report($survey_id, $user_id)
    {
        $table_survey_answer = Database::get_course_table(TABLE_SURVEY_ANSWER);
        $table_survey_invitation = Database::get_course_table(TABLE_SURVEY_INVITATION);
        $table_survey = Database::get_course_table(TABLE_SURVEY);

        $course_id = api_get_course_int_id();
        $survey_id = (int) $survey_id;
        $user_id = Database::escape_string($user_id);

        if (!empty($survey_id) && !empty($user_id)) {
            // delete data from survey_answer by user_id and survey_id
            $sql = "DELETE FROM $table_survey_answer
			        WHERE c_id = $course_id AND survey_id = '".$survey_id."' AND user = '".$user_id."'";
            Database::query($sql);
            // update field answered from survey_invitation by user_id and survey_id
            $sql = "UPDATE $table_survey_invitation SET answered = '0'
			        WHERE
			            c_id = $course_id AND
			            survey_code = (
                            SELECT code FROM $table_survey
                            WHERE
                                c_id = $course_id AND
                                survey_id = '".$survey_id."'
                        ) AND
			            user = '".$user_id."'";
            $result = Database::query($sql);
        }

        if ($result !== false) {
            $message = get_lang('SurveyUserAnswersHaveBeenRemovedSuccessfully').'<br />
					<a href="'.api_get_path(WEB_CODE_PATH).'survey/reporting.php?action=userreport&survey_id='
                .$survey_id.'">'.
                get_lang('GoBack').'</a>';
            echo Display::return_message($message, 'confirmation', false);
        }
    }

    /**
     * This function displays the user report which is basically nothing more
     * than a one-page display of all the questions
     * of the survey that is filled with the answers of the person who filled the survey.
     *
     * @return string html code of the one-page survey with the answers of the selected user
     * @author Patrick Cool <patrick.cool@UGent.be>, Ghent University
     * @version February 2007 - Updated March 2008
     */
    public static function display_user_report($people_filled, $survey_data)
    {
        // Database table definitions
        $table_survey_question = Database::get_course_table(TABLE_SURVEY_QUESTION);
        $table_survey_question_option = Database::get_course_table(TABLE_SURVEY_QUESTION_OPTION);
        $table_survey_answer = Database::get_course_table(TABLE_SURVEY_ANSWER);
        $surveyId = isset($_GET['survey_id']) ? (int) $_GET['survey_id'] : 0;

        // Actions bar
        echo '<div class="actions">';
        echo '<a href="'.api_get_path(WEB_CODE_PATH).'survey/reporting.php?survey_id='.$surveyId.'&'.api_get_cidreq()
            .'">'.
            Display::return_icon('back.png', get_lang('BackTo').' '.get_lang('ReportingOverview'), '', ICON_SIZE_MEDIUM)
            .'</a>';
        if (isset($_GET['user'])) {
            if (api_is_allowed_to_edit()) {
                // The delete link
                echo '<a href="'.api_get_path(WEB_CODE_PATH).'survey/reporting.php?action=deleteuserreport&survey_id='
                    .$surveyId.'&'.api_get_cidreq().'&user='.Security::remove_XSS($_GET['user']).'" >'.
                    Display::return_icon('delete.png', get_lang('Delete'), '', ICON_SIZE_MEDIUM).'</a>';
            }

            // Export the user report
            echo '<a href="javascript: void(0);" onclick="document.form1a.submit();">'
                .Display::return_icon('export_csv.png', get_lang('ExportAsCSV'), '', ICON_SIZE_MEDIUM).'</a> ';
            echo '<a href="javascript: void(0);" onclick="document.form1b.submit();">'
                .Display::return_icon('export_excel.png', get_lang('ExportAsXLS'), '', ICON_SIZE_MEDIUM).'</a> ';
            echo '<form id="form1a" name="form1a" method="post" action="'.api_get_self().'?action='
                .Security::remove_XSS($_GET['action']).'&survey_id='.$surveyId.'&'.api_get_cidreq().'&user_id='
                .Security::remove_XSS($_GET['user']).'">';
            echo '<input type="hidden" name="export_report" value="export_report">';
            echo '<input type="hidden" name="export_format" value="csv">';
            echo '</form>';
            echo '<form id="form1b" name="form1b" method="post" action="'.api_get_self().'?action='
                .Security::remove_XSS($_GET['action']).'&survey_id='.$surveyId.'&'.api_get_cidreq().'&user_id='
                .Security::remove_XSS($_GET['user']).'">';
            echo '<input type="hidden" name="export_report" value="export_report">';
            echo '<input type="hidden" name="export_format" value="xls">';
            echo '</form>';
            echo '<form id="form2" name="form2" method="post" action="'.api_get_self().'?action='
                .Security::remove_XSS($_GET['action']).'&survey_id='.$surveyId.'&'.api_get_cidreq().'">';
        }
        echo '</div>';

        // Step 1: selection of the user
        echo "<script>
        function jumpMenu(targ,selObj,restore) {
            eval(targ+\".location='\"+selObj.options[selObj.selectedIndex].value+\"'\");
            if (restore) selObj.selectedIndex=0;
        }
		</script>";
        echo get_lang('SelectUserWhoFilledSurvey').'<br />';

        echo '<select name="user" onchange="jumpMenu(\'parent\',this,0)">';
        echo '<option value="'.api_get_path(WEB_CODE_PATH).'survey/reporting.php?action='
            .Security::remove_XSS($_GET['action']).'&survey_id='.Security::remove_XSS($_GET['survey_id']).'">'
            .get_lang('SelectUser').'</option>';

        foreach ($people_filled as $key => & $person) {
            if ($survey_data['anonymous'] == 0) {
                $name = $person['user_info']['complete_name_with_username'];
                $id = $person['user_id'];
                if ($id == '') {
                    $id = $person['invited_user'];
                    $name = $person['invited_user'];
                }
            } else {
                $name = get_lang('Anonymous').' '.($key + 1);
                $id = $person;
            }
            echo '<option value="'.api_get_path(WEB_CODE_PATH).'survey/reporting.php?action='
                .Security::remove_XSS($_GET['action']).'&survey_id='.Security::remove_XSS($_GET['survey_id']).'&user='
                .Security::remove_XSS($id).'" ';
            if (isset($_GET['user']) && $_GET['user'] == $id) {
                echo 'selected="selected"';
            }
            echo '>'.$name.'</option>';
        }
        echo '</select>';

        $course_id = api_get_course_int_id();
        // Step 2: displaying the survey and the answer of the selected users
        if (isset($_GET['user'])) {
            echo Display::return_message(
                get_lang('AllQuestionsOnOnePage'),
                'normal',
                false
            );

            // Getting all the questions and options
            $sql = "SELECT
			            survey_question.question_id,
			            survey_question.survey_id,
			            survey_question.survey_question,
			            survey_question.display,
			            survey_question.max_value,
			            survey_question.sort,
			            survey_question.type,
                        survey_question_option.question_option_id,
                        survey_question_option.option_text,
                        survey_question_option.sort as option_sort
					FROM $table_survey_question survey_question
					LEFT JOIN $table_survey_question_option survey_question_option
					ON
					    survey_question.question_id = survey_question_option.question_id AND
					    survey_question_option.c_id = $course_id
					WHERE
					    survey_question.survey_id = '".Database::escape_string($_GET['survey_id'])."' AND
                        survey_question.c_id = $course_id
					ORDER BY survey_question.sort, survey_question_option.sort ASC";
            $result = Database::query($sql);
            while ($row = Database::fetch_array($result, 'ASSOC')) {
                if ($row['type'] != 'pagebreak') {
                    $questions[$row['sort']]['question_id'] = $row['question_id'];
                    $questions[$row['sort']]['survey_id'] = $row['survey_id'];
                    $questions[$row['sort']]['survey_question'] = $row['survey_question'];
                    $questions[$row['sort']]['display'] = $row['display'];
                    $questions[$row['sort']]['type'] = $row['type'];
                    $questions[$row['sort']]['maximum_score'] = $row['max_value'];
                    $questions[$row['sort']]['options'][$row['question_option_id']] = $row['option_text'];
                }
            }

            // Getting all the answers of the user
            $sql = "SELECT * FROM $table_survey_answer
			        WHERE
                        c_id = $course_id AND
                        survey_id = '".intval($_GET['survey_id'])."' AND
                        user = '".Database::escape_string($_GET['user'])."'";
            $result = Database::query($sql);
            while ($row = Database::fetch_array($result, 'ASSOC')) {
                $answers[$row['question_id']][] = $row['option_id'];
                $all_answers[$row['question_id']][] = $row;
            }

            // Displaying all the questions

            foreach ($questions as & $question) {
                // If the question type is a scoring then we have to format the answers differently
                switch ($question['type']) {
                    case 'score':
                        $finalAnswer = array();
                        if (is_array($question) && is_array($all_answers)) {
                            foreach ($all_answers[$question['question_id']] as $key => & $answer_array) {
                                $finalAnswer[$answer_array['option_id']] = $answer_array['value'];
                            }
                        }
                        break;
                    case 'multipleresponse':
                        $finalAnswer = isset($answers[$question['question_id']])
                            ? $answers[$question['question_id']]
                            : '';
                        break;
                    default:
                        $finalAnswer = '';
                        if (isset($all_answers[$question['question_id']])) {
                            $finalAnswer = $all_answers[$question['question_id']][0]['option_id'];
                        }
                        break;
                }

                $ch_type = 'ch_'.$question['type'];
                /** @var survey_question $display */
                $display = new $ch_type;

                $url = api_get_self();
                $form = new FormValidator('question', 'post', $url);
                $form->addHtml('<div class="survey_question_wrapper"><div class="survey_question">');
                $form->addHtml($question['survey_question']);
                $display->render($form, $question, $finalAnswer);
                $form->addHtml('</div></div>');
                $form->display();
            }
        }
    }

    /**
     * This function displays the report by question.
     *
     * It displays a table with all the options of the question and the number of users who have answered positively on
     * the option. The number of users who answered positive on a given option is expressed in an absolute number, in a
     * percentage of the total and graphically using bars By clicking on the absolute number you get a list with the
     * persons who have answered this. You can then click on the name of the person and you will then go to the report
     * by user where you see all the answers of that user.
     *
     * @param    array    All the survey data
     * @return   string    html code that displays the report by question
     * @todo allow switching between horizontal and vertical.
     * @todo multiple response: percentage are probably not OK
     * @todo the question and option text have to be shortened and should expand when the user clicks on it.
     * @todo the pagebreak and comment question types should not be shown => removed from $survey_data before
     * @author Patrick Cool <patrick.cool@UGent.be>, Ghent University
     * @version February 2007 - Updated March 2008
     */
    public static function display_question_report($survey_data)
    {
        $singlePage = isset($_GET['single_page']) ? intval($_GET['single_page']) : 0;
        $course_id = api_get_course_int_id();
        // Database table definitions
        $table_survey_question = Database::get_course_table(TABLE_SURVEY_QUESTION);
        $table_survey_question_option = Database::get_course_table(TABLE_SURVEY_QUESTION_OPTION);
        $table_survey_answer = Database::get_course_table(TABLE_SURVEY_ANSWER);

        // Determining the offset of the sql statement (the n-th question of the survey)
        $offset = !isset($_GET['question']) ? 0 : intval($_GET['question']);
        $currentQuestion = isset($_GET['question']) ? intval($_GET['question']) : 0;
        $questions = array();
        $surveyId = intval($_GET['survey_id']);
        $action = Security::remove_XSS($_GET['action']);

        echo '<div class="actions">';
        echo '<a href="'.api_get_path(WEB_CODE_PATH).'survey/reporting.php?survey_id='.$surveyId.'">'.
            Display::return_icon(
                'back.png',
                get_lang('BackTo').' '.get_lang('ReportingOverview'),
                '',
                ICON_SIZE_MEDIUM
            ).'</a>';
        echo '</div>';

        if ($survey_data['number_of_questions'] > 0) {
            $limitStatement = null;
            if (!$singlePage) {
                echo '<div id="question_report_questionnumbers" class="pagination">';
                if ($currentQuestion != 0) {
                    echo '<li><a href="'.api_get_path(WEB_CODE_PATH).'survey/reporting.php?action='.$action.'&'
                        .api_get_cidreq().'&survey_id='.$surveyId.'&question='.($offset - 1).'">'
                        .get_lang('PreviousQuestion').'</a></li>';
                }

                for ($i = 1; $i <= $survey_data['number_of_questions']; $i++) {
                    if ($offset != $i - 1) {
                        echo '<li><a href="'.api_get_path(WEB_CODE_PATH).'survey/reporting.php?action='.$action.'&'
                            .api_get_cidreq().'&survey_id='.$surveyId.'&question='.($i - 1).'">'.$i.'</a></li>';
                    } else {
                        echo '<li class="disabled"s><a href="#">'.$i.'</a></li>';
                    }
                }
                if ($currentQuestion < ($survey_data['number_of_questions'] - 1)) {
                    echo '<li><a href="'.api_get_path(WEB_CODE_PATH).'survey/reporting.php?action='.$action.'&'
                        .api_get_cidreq().'&survey_id='.$surveyId.'&question='.($offset + 1).'">'
                        .get_lang('NextQuestion').'</li></a>';
                }
                echo '</ul>';
                echo '</div>';
                $limitStatement = " LIMIT $offset, 1";
            }

            // Getting the question information
            $sql = "SELECT * FROM $table_survey_question
			        WHERE
			            c_id = $course_id AND
                        survey_id='".Database::escape_string($_GET['survey_id'])."' AND
                        type <>'pagebreak' AND 
                        type <>'comment'
                    ORDER BY sort ASC
                    $limitStatement";
            $result = Database::query($sql);
            while ($row = Database::fetch_array($result)) {
                $questions[$row['question_id']] = $row;
            }
        }

        foreach ($questions as $question) {
            $chartData = array();
            $options = array();
            echo '<div class="title-question">';
            echo strip_tags(isset($question['survey_question']) ? $question['survey_question'] : null);
            echo '</div>';

            if ($question['type'] == 'score') {
                /** @todo This function should return the options as this is needed further in the code */
                $options = self::display_question_report_score($survey_data, $question, $offset);
            } elseif ($question['type'] == 'open') {
                /** @todo Also get the user who has answered this */
                $sql = "SELECT * FROM $table_survey_answer
                        WHERE
                            c_id = $course_id AND
                            survey_id='".intval($_GET['survey_id'])."' AND
                            question_id = '".intval($question['question_id'])."'";
                $result = Database::query($sql);
                while ($row = Database::fetch_array($result, 'ASSOC')) {
                    echo $row['option_id'].'<hr noshade="noshade" size="1" />';
                }
            } else {
                // Getting the options ORDER BY sort ASC
                $sql = "SELECT * FROM $table_survey_question_option
                        WHERE
                            c_id = $course_id AND
                            survey_id='".intval($_GET['survey_id'])."'
                            AND question_id = '".intval($question['question_id'])."'
                        ORDER BY sort ASC";
                $result = Database::query($sql);
                while ($row = Database::fetch_array($result, 'ASSOC')) {
                    $options[$row['question_option_id']] = $row;
                }
                // Getting the answers
                $sql = "SELECT *, count(answer_id) as total FROM $table_survey_answer
                        WHERE
                            c_id = $course_id AND
                            survey_id='".intval($_GET['survey_id'])."'
                            AND question_id = '".intval($question['question_id'])."'
                        GROUP BY option_id, value";
                $result = Database::query($sql);
                $number_of_answers = array();
                $data = array();
                while ($row = Database::fetch_array($result, 'ASSOC')) {
                    if (!isset($number_of_answers[$row['question_id']])) {
                        $number_of_answers[$row['question_id']] = 0;
                    }
                    $number_of_answers[$row['question_id']] += $row['total'];
                    $data[$row['option_id']] = $row;
                }

                foreach ($options as $option) {
                    $optionText = strip_tags($option['option_text']);
                    $optionText = html_entity_decode($optionText);
                    $votes = isset($data[$option['question_option_id']]['total']) ?
                        $data[$option['question_option_id']]['total'] : '0';
                    array_push($chartData, array('option' => $optionText, 'votes' => $votes));
                }
                $chartContainerId = 'chartContainer'.$question['question_id'];
                echo '<div id="'.$chartContainerId.'" class="col-md-12">';
                echo self::drawChart($chartData, false, $chartContainerId);

                // displaying the table: headers
                echo '<table class="display-survey table">';
                echo '	<tr>';
                echo '		<th>&nbsp;</th>';
                echo '		<th>'.get_lang('AbsoluteTotal').'</th>';
                echo '		<th>'.get_lang('Percentage').'</th>';
                echo '		<th>'.get_lang('VisualRepresentation').'</th>';
                echo '	<tr>';

                // Displaying the table: the content
                if (is_array($options)) {
                    foreach ($options as $key => & $value) {
                        $absolute_number = null;
                        if (isset($data[$value['question_option_id']])) {
                            $absolute_number = $data[$value['question_option_id']]['total'];
                        }
                        if ($question['type'] == 'percentage' && empty($absolute_number)) {
                            continue;
                        }
                        $number_of_answers[$option['question_id']] = isset($number_of_answers[$option['question_id']])
                            ? $number_of_answers[$option['question_id']]
                            : 0;
                        if ($number_of_answers[$option['question_id']] == 0) {
                            $answers_number = 0;
                        } else {
                            $answers_number = $absolute_number / $number_of_answers[$option['question_id']] * 100;
                        }
                        echo '	<tr>';
                        echo '		<td class="center">'.$value['option_text'].'</td>';
                        echo '		<td class="center">';
                        if ($absolute_number != 0) {
                            echo '<a href="'.api_get_path(WEB_CODE_PATH).'survey/reporting.php?action='.$action
                                .'&survey_id='.$surveyId.'&question='.$offset.'&viewoption='
                                .$value['question_option_id'].'">'.$absolute_number.'</a>';
                        } else {
                            echo '0';
                        }

                        echo '      </td>';
                        echo '		<td class="center">'.round($answers_number, 2).' %</td>';
                        echo '		<td class="center">';
                        $size = $answers_number * 2;
                        if ($size > 0) {
                            echo '<div style="border:1px solid #264269; background-color:#aecaf4; height:10px; width:'
                                .$size.'px">&nbsp;</div>';
                        } else {
                            echo '<div style="text-align: left;">'.get_lang("NoDataAvailable").'</div>';
                        }
                        echo ' </td>';
                        echo ' </tr>';
                    }
                }
                // displaying the table: footer (totals)
                echo '	<tr>';
                echo '		<td class="total"><b>'.get_lang('Total').'</b></td>';
                echo '		<td class="total"><b>'
                    .($number_of_answers[$option['question_id']] == 0
                        ? '0'
                        : $number_of_answers[$option['question_id']])
                    .'</b></td>';
                echo '		<td class="total">&nbsp;</td>';
                echo '		<td class="total">&nbsp;</td>';
                echo '	</tr>';
                echo '</table>';
                echo '</div>';
            }
        }
        if (isset($_GET['viewoption'])) {
            echo '<div class="answered-people">';
            echo '<h4>'.get_lang('PeopleWhoAnswered').': '
                .strip_tags($options[Security::remove_XSS($_GET['viewoption'])]['option_text']).'</h4>';

            if (is_numeric($_GET['value'])) {
                $sql_restriction = "AND value='".Database::escape_string($_GET['value'])."'";
            }

            $sql = "SELECT user FROM $table_survey_answer
                    WHERE
                        c_id = $course_id AND
                        option_id = '".Database::escape_string($_GET['viewoption'])."'
                        $sql_restriction";
            $result = Database::query($sql);
            echo '<ul>';
            while ($row = Database::fetch_array($result, 'ASSOC')) {
                $user_info = api_get_user_info($row['user']);
                echo '<li><a href="'.api_get_path(WEB_CODE_PATH).'survey/reporting.php?action=userreport&survey_id='
                    .$surveyId.'&user='.$row['user'].'">'
                    .$user_info['complete_name_with_username']
                    .'</a></li>';
            }
            echo '</ul>';
            echo '</div>';
        }
    }

    /**
     * Display score data about a survey question
     * @param    array    Question info
     * @param    integer    The offset of results shown
     * @return   void    (direct output)
     */
    public static function display_question_report_score($survey_data, $question, $offset)
    {
        // Database table definitions
        $table_survey_question_option = Database::get_course_table(TABLE_SURVEY_QUESTION_OPTION);
        $table_survey_answer = Database::get_course_table(TABLE_SURVEY_ANSWER);
        $course_id = api_get_course_int_id();

        // Getting the options
        $sql = "SELECT * FROM $table_survey_question_option
                WHERE
                    c_id = $course_id AND
                    survey_id='".Database::escape_string($_GET['survey_id'])."' AND
                    question_id = '".Database::escape_string($question['question_id'])."'
                ORDER BY sort ASC";
        $result = Database::query($sql);
        while ($row = Database::fetch_array($result)) {
            $options[$row['question_option_id']] = $row;
        }

        // Getting the answers
        $sql = "SELECT *, count(answer_id) as total 
                FROM $table_survey_answer
                WHERE
                   c_id = $course_id AND
                   survey_id='".Database::escape_string($_GET['survey_id'])."' AND
                   question_id = '".Database::escape_string($question['question_id'])."'
                GROUP BY option_id, value";
        $result = Database::query($sql);
        $number_of_answers = 0;
        while ($row = Database::fetch_array($result)) {
            $number_of_answers += $row['total'];
            $data[$row['option_id']][$row['value']] = $row;
        }

        $chartData = array();
        foreach ($options as $option) {
            $optionText = strip_tags($option['option_text']);
            $optionText = html_entity_decode($optionText);
            for ($i = 1; $i <= $question['max_value']; $i++) {
                $votes = $data[$option['question_option_id']][$i]['total'];
                if (empty($votes)) {
                    $votes = '0';
                }
                array_push(
                    $chartData,
                    array(
                        'serie' => $optionText,
                        'option' => $i,
                        'votes' => $votes
                    )
                );
            }
        }
        echo '<div id="chartContainer" class="col-md-12">';
        echo self::drawChart($chartData, true);
        echo '</div>';

        // Displaying the table: headers
        echo '<table class="data_table">';
        echo '	<tr>';
        echo '		<th>&nbsp;</th>';
        echo '		<th>'.get_lang('Score').'</th>';
        echo '		<th>'.get_lang('AbsoluteTotal').'</th>';
        echo '		<th>'.get_lang('Percentage').'</th>';
        echo '		<th>'.get_lang('VisualRepresentation').'</th>';
        echo '	<tr>';
        // Displaying the table: the content
        foreach ($options as $key => & $value) {
            for ($i = 1; $i <= $question['max_value']; $i++) {
                $absolute_number = $data[$value['question_option_id']][$i]['total'];
                echo '	<tr>';
                echo '		<td>'.$value['option_text'].'</td>';
                echo '		<td>'.$i.'</td>';
                echo '		<td><a href="'.api_get_path(WEB_CODE_PATH).'survey/reporting.php?action='.$action
                    .'&survey_id='.Security::remove_XSS($_GET['survey_id']).'&question='.Security::remove_XSS($offset)
                    .'&viewoption='.$value['question_option_id'].'&value='.$i.'">'.$absolute_number.'</a></td>';
                echo '		<td>'.round($absolute_number / $number_of_answers * 100, 2).' %</td>';
                echo '		<td>';
                $size = ($absolute_number / $number_of_answers * 100 * 2);
                if ($size > 0) {
                    echo '			<div style="border:1px solid #264269; background-color:#aecaf4; height:10px; width:'
                        .$size.'px">&nbsp;</div>';
                }
                echo '		</td>';
                echo '	</tr>';
            }
        }
        // Displaying the table: footer (totals)
        echo '	<tr>';
        echo '		<td style="border-top:1px solid black"><b>'.get_lang('Total').'</b></td>';
        echo '		<td style="border-top:1px solid black">&nbsp;</td>';
        echo '		<td style="border-top:1px solid black"><b>'.$number_of_answers.'</b></td>';
        echo '		<td style="border-top:1px solid black">&nbsp;</td>';
        echo '		<td style="border-top:1px solid black">&nbsp;</td>';
        echo '	</tr>';

        echo '</table>';
    }

    /**
     * This functions displays the complete reporting
     * @return string    HTML code
     * @todo open questions are not in the complete report yet.
     * @author Patrick Cool <patrick.cool@UGent.be>, Ghent University
     * @version February 2007
     */
    public static function display_complete_report($survey_data)
    {
        // Database table definitions
        $table_survey_question = Database::get_course_table(TABLE_SURVEY_QUESTION);
        $table_survey_question_option = Database::get_course_table(TABLE_SURVEY_QUESTION_OPTION);
        $table_survey_answer = Database::get_course_table(TABLE_SURVEY_ANSWER);

        $surveyId = isset($_GET['survey_id']) ? intval($_GET['survey_id']) : 0;
        $action = isset($_GET['action']) ? Security::remove_XSS($_GET['action']) : '';

        // Actions bar
        echo '<div class="actions">';
        echo '<a href="'.api_get_path(WEB_CODE_PATH).'survey/reporting.php?survey_id='
            .Security::remove_XSS($_GET['survey_id']).'">'
            .Display::return_icon(
                'back.png',
                get_lang('BackTo').' '.get_lang('ReportingOverview'),
                [],
                ICON_SIZE_MEDIUM
            )
            .'</a>';
        echo '<a class="survey_export_link" href="javascript: void(0);" onclick="document.form1a.submit();">'
            .Display::return_icon('export_csv.png', get_lang('ExportAsCSV'), '', ICON_SIZE_MEDIUM).'</a>';
        echo '<a class="survey_export_link" href="javascript: void(0);" onclick="document.form1b.submit();">'
            .Display::return_icon('export_excel.png', get_lang('ExportAsXLS'), '', ICON_SIZE_MEDIUM).'</a>';
        echo '</div>';

        // The form
        echo '<form id="form1a" name="form1a" method="post" action="'.api_get_self().'?action='.$action.'&survey_id='
            .$surveyId.'&'.api_get_cidreq().'">';
        echo '<input type="hidden" name="export_report" value="export_report">';
        echo '<input type="hidden" name="export_format" value="csv">';
        echo '</form>';
        echo '<form id="form1b" name="form1b" method="post" action="'.api_get_self().'?action='.$action.'&survey_id='
            .$surveyId.'&'.api_get_cidreq().'">';
        echo '<input type="hidden" name="export_report" value="export_report">';
        echo '<input type="hidden" name="export_format" value="xls">';
        echo '</form>';

        echo '<form id="form2" name="form2" method="post" action="'.api_get_self().'?action='.$action.'&survey_id='
            .$surveyId.'&'.api_get_cidreq().'">';

        // The table
        echo '<br /><table class="data_table" border="1">';
        // Getting the number of options per question
        echo '	<tr>';
        echo '		<th>';
        if ((isset($_POST['submit_question_filter']) && $_POST['submit_question_filter']) ||
            (isset($_POST['export_report']) && $_POST['export_report'])
        ) {
            echo '<button class="cancel" type="submit" name="reset_question_filter" value="'
                .get_lang('ResetQuestionFilter').'">'.get_lang('ResetQuestionFilter').'</button>';
        }
        echo '<button class="save" type="submit" name="submit_question_filter" value="'.get_lang('SubmitQuestionFilter')
            .'">'.get_lang('SubmitQuestionFilter').'</button>';
        echo '</th>';

        $display_extra_user_fields = false;
        if (!(isset($_POST['submit_question_filter']) && $_POST['submit_question_filter'] ||
                isset($_POST['export_report']) && $_POST['export_report']) ||
            !empty($_POST['fields_filter'])
        ) {
            // Show user fields section with a big th colspan that spans over all fields
            $extra_user_fields = UserManager::get_extra_fields(
                0,
                0,
                5,
                'ASC',
                false,
                true
            );
            $num = count($extra_user_fields);
            if ($num > 0) {
                echo '<th '.($num > 0 ? ' colspan="'.$num.'"' : '').'>';
                echo '<label><input type="checkbox" name="fields_filter" value="1" checked="checked"/> ';
                echo get_lang('UserFields');
                echo '</label>';
                echo '</th>';
                $display_extra_user_fields = true;
            }
        }

        $course_id = api_get_course_int_id();
        $sql = "SELECT q.question_id, q.type, q.survey_question, count(o.question_option_id) as number_of_options
				FROM $table_survey_question q 
				LEFT JOIN $table_survey_question_option o
				ON q.question_id = o.question_id
				WHERE 
				    q.survey_id = '".$surveyId."' AND
				    q.c_id = $course_id AND
				    o.c_id = $course_id
				GROUP BY q.question_id
				ORDER BY q.sort ASC";
        $result = Database::query($sql);
        $questions = [];
        while ($row = Database::fetch_array($result)) {
            // We show the questions if
            // 1. there is no question filter and the export button has not been clicked
            // 2. there is a quesiton filter but the question is selected for display
            if (!(isset($_POST['submit_question_filter']) && $_POST['submit_question_filter']) ||
                (is_array($_POST['questions_filter']) && in_array($row['question_id'], $_POST['questions_filter']))
            ) {
                // We do not show comment and pagebreak question types
                if ($row['type'] != 'comment' && $row['type'] != 'pagebreak') {
                    echo ' <th';
                    // <hub> modified tst to include percentage
                    if ($row['number_of_options'] > 0 && $row['type'] != 'percentage') {
                        // </hub>
                        echo ' colspan="'.$row['number_of_options'].'"';
                    }
                    echo '>';

                    echo '<label><input type="checkbox" name="questions_filter[]" value="'.$row['question_id']
                        .'" checked="checked"/> ';
                    echo $row['survey_question'];
                    echo '</label>';
                    echo '</th>';
                }
                // No column at all if it's not a question
            }
            $questions[$row['question_id']] = $row;
        }
        echo '	</tr>';
        // Getting all the questions and options
        echo '	<tr>';
        echo '		<th>&nbsp;</th>'; // the user column

        if (!(isset($_POST['submit_question_filter']) && $_POST['submit_question_filter'] ||
                isset($_POST['export_report']) && $_POST['export_report']) || !empty($_POST['fields_filter'])) {
            //show the fields names for user fields
            foreach ($extra_user_fields as & $field) {
                echo '<th>'.$field[3].'</th>';
            }
        }

        // cells with option (none for open question)
        $sql = "SELECT 	
                    sq.question_id, sq.survey_id,
                    sq.survey_question, sq.display,
                    sq.sort, sq.type, sqo.question_option_id,
                    sqo.option_text, sqo.sort as option_sort
				FROM $table_survey_question sq
				LEFT JOIN $table_survey_question_option sqo
				ON sq.question_id = sqo.question_id
				WHERE
				    sq.survey_id = '".$surveyId."' AND
                    sq.c_id = $course_id AND
                    sqo.c_id = $course_id
				ORDER BY sq.sort ASC, sqo.sort ASC";
        $result = Database::query($sql);

        $display_percentage_header = 1;
        $possible_answers = [];
        // in order to display only once the cell option (and not 100 times)
        while ($row = Database::fetch_array($result)) {
            // We show the options if
            // 1. there is no question filter and the export button has not been clicked
            // 2. there is a question filter but the question is selected for display
            if (!(isset($_POST['submit_question_filter']) && $_POST['submit_question_filter']) ||
                (is_array($_POST['questions_filter']) && in_array($row['question_id'], $_POST['questions_filter']))
            ) {
                // <hub> modif 05-05-2010
                // we do not show comment and pagebreak question types
                if ($row['type'] == 'open') {
                    echo '<th>&nbsp;-&nbsp;</th>';
                    $possible_answers[$row['question_id']][$row['question_option_id']] = $row['question_option_id'];
                    $display_percentage_header = 1;
                } elseif ($row['type'] == 'percentage' && $display_percentage_header) {
                    echo '<th>&nbsp;%&nbsp;</th>';
                    $possible_answers[$row['question_id']][$row['question_option_id']] = $row['question_option_id'];
                    $display_percentage_header = 0;
                } elseif ($row['type'] == 'percentage') {
                    $possible_answers[$row['question_id']][$row['question_option_id']] = $row['question_option_id'];
                } elseif ($row['type'] <> 'comment' && $row['type'] <> 'pagebreak' && $row['type'] <> 'percentage') {
                    echo '<th>';
                    echo $row['option_text'];
                    echo '</th>';
                    $possible_answers[$row['question_id']][$row['question_option_id']] = $row['question_option_id'];
                    $display_percentage_header = 1;
                }
                //no column at all if the question was not a question
                // </hub>
            }
        }

        echo '	</tr>';

        // Getting all the answers of the users
        $old_user = '';
        $answers_of_user = array();
        $sql = "SELECT * FROM $table_survey_answer
                WHERE
                    c_id = $course_id AND
                    survey_id='".$surveyId."'
                ORDER BY answer_id, user ASC";
        $result = Database::query($sql);
        $i = 1;
        while ($row = Database::fetch_array($result)) {
            if ($old_user != $row['user'] && $old_user != '') {
                $userParam = $old_user;
                if ($survey_data['anonymous'] != 0) {
                    $userParam = $i;
                    $i++;
                }
                self::display_complete_report_row(
                    $survey_data,
                    $possible_answers,
                    $answers_of_user,
                    $userParam,
                    $questions,
                    $display_extra_user_fields
                );
                $answers_of_user = array();
            }
            if (isset($questions[$row['question_id']]) && $questions[$row['question_id']]['type'] != 'open') {
                $answers_of_user[$row['question_id']][$row['option_id']] = $row;
            } else {
                $answers_of_user[$row['question_id']][0] = $row;
            }
            $old_user = $row['user'];
        }
        $userParam = $old_user;
        if ($survey_data['anonymous'] != 0) {
            $userParam = $i;
            $i++;
        }
        self::display_complete_report_row(
            $survey_data,
            $possible_answers,
            $answers_of_user,
            $userParam,
            $questions,
            $display_extra_user_fields
        );
        // This is to display the last user
        echo '</table>';
        echo '</form>';
    }

    /**
     * This function displays a row (= a user and his/her answers) in the table of the complete report.
     *
     * @param array $survey_data
     * @param array    Possible options
     * @param array    User answers
     * @param mixed    User ID or user details string
     * @param boolean  Whether to show extra user fields or not
     * @author Patrick Cool <patrick.cool@UGent.be>, Ghent University
     * @version February 2007 - Updated March 2008
     */
    public static function display_complete_report_row(
        $survey_data,
        $possible_options,
        $answers_of_user,
        $user,
        $questions,
        $display_extra_user_fields = false
    ) {
        $user = Security::remove_XSS($user);
        echo '<tr>';
        if ($survey_data['anonymous'] == 0) {
            if (intval($user) !== 0) {
                $userInfo = api_get_user_info($user);
                if (!empty($userInfo)) {
                    $user_displayed = $userInfo['complete_name_with_username'];
                } else {
                    $user_displayed = '-';
                }
                echo '<th><a href="'.api_get_self().'?action=userreport&survey_id='
                    .Security::remove_XSS($_GET['survey_id']).'&user='.$user.'">'
                    .$user_displayed.'</a></th>'; // the user column
            } else {
                echo '<th>'.$user.'</th>'; // the user column
            }
        } else {
            echo '<th>'.get_lang('Anonymous').' '.$user.'</th>';
        }

        if ($display_extra_user_fields) {
            // Show user fields data, if any, for this user
            $user_fields_values = UserManager::get_extra_user_data(
                intval($user),
                false,
                false,
                false,
                true
            );
            foreach ($user_fields_values as & $value) {
                echo '<td align="center">'.$value.'</td>';
            }
        }
        if (is_array($possible_options)) {
            // <hub> modified to display open answers and percentage
            foreach ($possible_options as $question_id => & $possible_option) {
                if ($questions[$question_id]['type'] == 'open') {
                    echo '<td align="center">';
                    echo $answers_of_user[$question_id]['0']['option_id'];
                    echo '</td>';
                } else {
                    foreach ($possible_option as $option_id => & $value) {
                        if ($questions[$question_id]['type'] == 'percentage') {
                            if (!empty($answers_of_user[$question_id][$option_id])) {
                                echo "<td align='center'>";
                                echo $answers_of_user[$question_id][$option_id]['value'];
                                echo "</td>";
                            }
                        } else {
                            echo '<td align="center">';
                            if (!empty($answers_of_user[$question_id][$option_id])) {
                                if ($answers_of_user[$question_id][$option_id]['value'] != 0) {
                                    echo $answers_of_user[$question_id][$option_id]['value'];
                                } else {
                                    echo 'v';
                                }
                            }
                        }
                    }
                }
            }
        }
        echo '</tr>';
    }

    /**
     * Quite similar to display_complete_report(), returns an HTML string
     * that can be used in a csv file
     * @todo consider merging this function with display_complete_report
     * @return    string    The contents of a csv file
     * @author Patrick Cool <patrick.cool@UGent.be>, Ghent University
     * @version February 2007
     */
    public static function export_complete_report($survey_data, $user_id = 0)
    {
        // Database table definitions
        $table_survey_question = Database::get_course_table(TABLE_SURVEY_QUESTION);
        $table_survey_question_option = Database::get_course_table(TABLE_SURVEY_QUESTION_OPTION);
        $table_survey_answer = Database::get_course_table(TABLE_SURVEY_ANSWER);

        // The first column
        $return = ';';

        // Show extra fields blank space (enough for extra fields on next line)
        $extra_user_fields = UserManager::get_extra_fields(
            0,
            0,
            5,
            'ASC',
            false,
            true
        );

        $num = count($extra_user_fields);
        $return .= str_repeat(';', $num);

        $course_id = api_get_course_int_id();

        $sql = "SELECT
                    questions.question_id,
                    questions.type,
                    questions.survey_question,
                    count(options.question_option_id) as number_of_options
				FROM $table_survey_question questions
                LEFT JOIN $table_survey_question_option options
				ON questions.question_id = options.question_id AND options.c_id = $course_id
				WHERE
				    questions.survey_id = '".intval($_GET['survey_id'])."' AND
                    questions.c_id = $course_id
				GROUP BY questions.question_id
				ORDER BY questions.sort ASC";
        $result = Database::query($sql);
        while ($row = Database::fetch_array($result)) {
            // We show the questions if
            // 1. there is no question filter and the export button has not been clicked
            // 2. there is a quesiton filter but the question is selected for display
            if (!(isset($_POST['submit_question_filter'])) ||
                (isset($_POST['submit_question_filter']) &&
                    is_array($_POST['questions_filter']) &&
                    in_array($row['question_id'], $_POST['questions_filter']))
            ) {
                // We do not show comment and pagebreak question types
                if ($row['type'] != 'comment' && $row['type'] != 'pagebreak') {
                    if ($row['number_of_options'] == 0 && $row['type'] == 'open') {
                        $return .= str_replace(
                            "\r\n",
                            '  ',
                            api_html_entity_decode(strip_tags($row['survey_question']), ENT_QUOTES)
                        )
                        .';';
                    } else {
                        for ($ii = 0; $ii < $row['number_of_options']; $ii++) {
                            $return .= str_replace(
                                "\r\n",
                                '  ',
                                api_html_entity_decode(strip_tags($row['survey_question']), ENT_QUOTES)
                            )
                            .';';
                        }
                    }
                }
            }
        }
        $return .= "\n";

        // Getting all the questions and options
        $return .= ';';

        // Show the fields names for user fields
        if (!empty($extra_user_fields)) {
            foreach ($extra_user_fields as & $field) {
                $return .= '"'
                    .str_replace(
                        "\r\n",
                        '  ',
                        api_html_entity_decode(strip_tags($field[3]), ENT_QUOTES)
                    )
                    .'";';
            }
        }

        $sql = "SELECT
		            survey_question.question_id,
		            survey_question.survey_id,
		            survey_question.survey_question,
		            survey_question.display,
		            survey_question.sort,
		            survey_question.type,
                    survey_question_option.question_option_id,
                    survey_question_option.option_text,
                    survey_question_option.sort as option_sort
				FROM $table_survey_question survey_question
				LEFT JOIN $table_survey_question_option survey_question_option
				ON
				    survey_question.question_id = survey_question_option.question_id AND
				    survey_question_option.c_id = $course_id
				WHERE
				    survey_question.survey_id = '".intval($_GET['survey_id'])."' AND
				    survey_question.c_id = $course_id
				ORDER BY survey_question.sort ASC, survey_question_option.sort ASC";
        $result = Database::query($sql);
        $possible_answers = array();
        $possible_answers_type = array();
        while ($row = Database::fetch_array($result)) {
            // We show the options if
            // 1. there is no question filter and the export button has not been clicked
            // 2. there is a question filter but the question is selected for display
            if (!(isset($_POST['submit_question_filter'])) || (
                is_array($_POST['questions_filter']) &&
                in_array($row['question_id'], $_POST['questions_filter']))
            ) {
                // We do not show comment and pagebreak question types
                if ($row['type'] != 'comment' && $row['type'] != 'pagebreak') {
                    $row['option_text'] = str_replace(array("\r", "\n"), array('', ''), $row['option_text']);
                    $return .= api_html_entity_decode(strip_tags($row['option_text']), ENT_QUOTES).';';
                    $possible_answers[$row['question_id']][$row['question_option_id']] = $row['question_option_id'];
                    $possible_answers_type[$row['question_id']] = $row['type'];
                }
            }
        }
        $return .= "\n";

        // Getting all the answers of the users
        $old_user = '';
        $answers_of_user = array();
        $sql = "SELECT * FROM $table_survey_answer
		        WHERE c_id = $course_id AND survey_id='".Database::escape_string($_GET['survey_id'])."'";
        if ($user_id != 0) {
            $sql .= "AND user='".Database::escape_string($user_id)."' ";
        }
        $sql .= "ORDER BY user ASC";

        $open_question_iterator = 1;
        $result = Database::query($sql);
        while ($row = Database::fetch_array($result)) {
            if ($old_user != $row['user'] && $old_user != '') {
                $return .= self::export_complete_report_row(
                    $survey_data,
                    $possible_answers,
                    $answers_of_user,
                    $old_user,
                    true
                );
                $answers_of_user = array();
            }
            if ($possible_answers_type[$row['question_id']] == 'open') {
                $temp_id = 'open'.$open_question_iterator;
                $answers_of_user[$row['question_id']][$temp_id] = $row;
                $open_question_iterator++;
            } else {
                $answers_of_user[$row['question_id']][$row['option_id']] = $row;
            }
            $old_user = $row['user'];
        }
        // This is to display the last user
        $return .= self::export_complete_report_row(
            $survey_data,
            $possible_answers,
            $answers_of_user,
            $old_user,
            true
        );

        return $return;
    }

    /**
     * Add a line to the csv file
     *
     * @param array Possible answers
     * @param array User's answers
     * @param mixed User ID or user details as string - Used as a string in the result string
     * @param boolean Whether to display user fields or not
     * @return string One line of the csv file
     * @author Patrick Cool <patrick.cool@UGent.be>, Ghent University
     * @version February 2007
     */
    public static function export_complete_report_row(
        $survey_data,
        $possible_options,
        $answers_of_user,
        $user,
        $display_extra_user_fields = false
    ) {
        $return = '';
        if ($survey_data['anonymous'] == 0) {
            if (intval($user) !== 0) {
                $userInfo = api_get_user_info($user);
                if (!empty($userInfo)) {
                    $user_displayed = $userInfo['complete_name_with_username'];
                } else {
                    $user_displayed = '-';
                }
                $return .= $user_displayed.';';
            } else {
                $return .= $user.';';
            }
        } else {
            $return .= '-;'; // The user column
        }

        if ($display_extra_user_fields) {
            // Show user fields data, if any, for this user
            $user_fields_values = UserManager::get_extra_user_data(
                $user,
                false,
                false,
                false,
                true
            );
            foreach ($user_fields_values as & $value) {
                $return .= '"'.str_replace('"', '""', api_html_entity_decode(strip_tags($value), ENT_QUOTES)).'";';
            }
        }

        if (is_array($possible_options)) {
            foreach ($possible_options as $question_id => $possible_option) {
                if (is_array($possible_option) && count($possible_option) > 0) {
                    foreach ($possible_option as $option_id => & $value) {
                        $my_answer_of_user = !isset($answers_of_user[$question_id]) || isset($answers_of_user[$question_id]) && $answers_of_user[$question_id] == null ? array() : $answers_of_user[$question_id];
                        $key = array_keys($my_answer_of_user);
                        if (isset($key[0]) && substr($key[0], 0, 4) == 'open') {
                            $return .= '"'.
                                str_replace(
                                    '"',
                                    '""',
                                    api_html_entity_decode(
                                        strip_tags(
                                            $answers_of_user[$question_id][$key[0]]['option_id']
                                        ),
                                        ENT_QUOTES
                                    )
                                ).
                                '"';
                        } elseif (!empty($answers_of_user[$question_id][$option_id])) {
                            //$return .= 'v';
                            if ($answers_of_user[$question_id][$option_id]['value'] != 0) {
                                $return .= $answers_of_user[$question_id][$option_id]['value'];
                            } else {
                                $return .= 'v';
                            }
                        }
                        $return .= ';';
                    }
                }
            }
        }
        $return .= "\n";

        return $return;
    }

    /**
     * Quite similar to display_complete_report(), returns an HTML string
     * that can be used in a csv file
     * @todo consider merging this function with display_complete_report
     * @return string The contents of a csv file
     * @author Patrick Cool <patrick.cool@UGent.be>, Ghent University
     * @version February 2007
     */
    public static function export_complete_report_xls($survey_data, $filename, $user_id = 0)
    {
        $course_id = api_get_course_int_id();
        $surveyId = isset($_GET['survey_id']) ? (int) $_GET['survey_id'] : 0;

        if (empty($course_id) || empty($surveyId)) {

            return false;
        }

        $spreadsheet = new PHPExcel();
        $spreadsheet->setActiveSheetIndex(0);
        $worksheet = $spreadsheet->getActiveSheet();
        $line = 1;
        $column = 1; // Skip the first column (row titles)

        // Show extra fields blank space (enough for extra fields on next line)
        // Show user fields section with a big th colspan that spans over all fields
        $extra_user_fields = UserManager::get_extra_fields(
            0,
            0,
            5,
            'ASC',
            false,
            true
        );
        $num = count($extra_user_fields);
        for ($i = 0; $i < $num; $i++) {
            $worksheet->setCellValueByColumnAndRow($column, $line, '');
            $column++;
        }

        $display_extra_user_fields = true;

        // Database table definitions
        $table_survey_question = Database::get_course_table(TABLE_SURVEY_QUESTION);
        $table_survey_question_option = Database::get_course_table(TABLE_SURVEY_QUESTION_OPTION);
        $table_survey_answer = Database::get_course_table(TABLE_SURVEY_ANSWER);

        // First line (questions)
        $sql = "SELECT
                    questions.question_id,
                    questions.type,
                    questions.survey_question,
                    count(options.question_option_id) as number_of_options
				FROM $table_survey_question questions
				LEFT JOIN $table_survey_question_option options
                ON questions.question_id = options.question_id AND options.c_id = $course_id
				WHERE
				    questions.survey_id = $surveyId AND
				    questions.c_id = $course_id
				GROUP BY questions.question_id
				ORDER BY questions.sort ASC";
        $result = Database::query($sql);
        while ($row = Database::fetch_array($result)) {
            // We show the questions if
            // 1. there is no question filter and the export button has not been clicked
            // 2. there is a quesiton filter but the question is selected for display
            if (!(isset($_POST['submit_question_filter'])) ||
                (isset($_POST['submit_question_filter']) && is_array($_POST['questions_filter']) &&
                in_array($row['question_id'], $_POST['questions_filter']))
            ) {
                // We do not show comment and pagebreak question types
                if ($row['type'] != 'comment' && $row['type'] != 'pagebreak') {
                    if ($row['number_of_options'] == 0 && $row['type'] == 'open') {
                        $worksheet->setCellValueByColumnAndRow(
                            $column,
                            $line,
                            api_html_entity_decode(
                                strip_tags($row['survey_question']),
                                ENT_QUOTES
                            )
                        );
                        $column++;
                    } else {
                        for ($ii = 0; $ii < $row['number_of_options']; $ii++) {
                            $worksheet->setCellValueByColumnAndRow(
                                $column,
                                $line,
                                api_html_entity_decode(
                                    strip_tags($row['survey_question']),
                                    ENT_QUOTES
                                )
                            );
                            $column++;
                        }
                    }
                }
            }
        }

        $line++;
        $column = 1;
        // Show extra field values
        if ($display_extra_user_fields) {
            // Show the fields names for user fields
            foreach ($extra_user_fields as & $field) {
                $worksheet->setCellValueByColumnAndRow(
                    $column,
                    $line,
                    api_html_entity_decode(strip_tags($field[3]), ENT_QUOTES)
                );
                $column++;
            }
        }

        // Getting all the questions and options (second line)
        $sql = "SELECT
                    survey_question.question_id, 
                    survey_question.survey_id, 
                    survey_question.survey_question, 
                    survey_question.display, 
                    survey_question.sort, 
                    survey_question.type,
                    survey_question_option.question_option_id, 
                    survey_question_option.option_text, 
                    survey_question_option.sort as option_sort
				FROM $table_survey_question survey_question
				LEFT JOIN $table_survey_question_option survey_question_option
				ON 
				    survey_question.question_id = survey_question_option.question_id AND 
				    survey_question_option.c_id = $course_id
				WHERE 
				    survey_question.survey_id = $surveyId AND
				    survey_question.c_id = $course_id
				ORDER BY survey_question.sort ASC, survey_question_option.sort ASC";
        $result = Database::query($sql);
        $possible_answers = array();
        $possible_answers_type = array();
        while ($row = Database::fetch_array($result)) {
            // We show the options if
            // 1. there is no question filter and the export button has not been clicked
            // 2. there is a quesiton filter but the question is selected for display
            if (!isset($_POST['submit_question_filter']) ||
                (isset($_POST['questions_filter']) && is_array($_POST['questions_filter']) &&
                in_array($row['question_id'], $_POST['questions_filter']))
            ) {
                // We do not show comment and pagebreak question types
                if ($row['type'] != 'comment' && $row['type'] != 'pagebreak') {
                    $worksheet->setCellValueByColumnAndRow(
                        $column,
                        $line,
                        api_html_entity_decode(
                            strip_tags($row['option_text']),
                            ENT_QUOTES
                        )
                    );
                    $possible_answers[$row['question_id']][$row['question_option_id']] = $row['question_option_id'];
                    $possible_answers_type[$row['question_id']] = $row['type'];
                    $column++;
                }
            }
        }

        // Getting all the answers of the users
        $line++;
        $column = 0;
        $old_user = '';
        $answers_of_user = array();
        $sql = "SELECT * FROM $table_survey_answer
                WHERE c_id = $course_id AND survey_id = $surveyId";
        if ($user_id != 0) {
            $sql .= " AND user='".intval($user_id)."' ";
        }
        $sql .= " ORDER BY user ASC";

        $open_question_iterator = 1;
        $result = Database::query($sql);
        while ($row = Database::fetch_array($result)) {
            if ($old_user != $row['user'] && $old_user != '') {
                $return = self::export_complete_report_row_xls(
                    $survey_data,
                    $possible_answers,
                    $answers_of_user,
                    $old_user,
                    true
                );
                foreach ($return as $elem) {
                    $worksheet->setCellValueByColumnAndRow($column, $line, $elem);
                    $column++;
                }
                $answers_of_user = array();
                $line++;
                $column = 0;
            }
            if ($possible_answers_type[$row['question_id']] == 'open') {
                $temp_id = 'open'.$open_question_iterator;
                $answers_of_user[$row['question_id']][$temp_id] = $row;
                $open_question_iterator++;
            } else {
                $answers_of_user[$row['question_id']][$row['option_id']] = $row;
            }
            $old_user = $row['user'];
        }

        $return = self::export_complete_report_row_xls(
            $survey_data,
            $possible_answers,
            $answers_of_user,
            $old_user,
            true
        );

        // this is to display the last user
        foreach ($return as $elem) {
            $worksheet->setCellValueByColumnAndRow($column, $line, $elem);
            $column++;
        }

        $file = api_get_path(SYS_ARCHIVE_PATH).api_replace_dangerous_char($filename);
        $writer = new PHPExcel_Writer_Excel2007($spreadsheet);
        $writer->save($file);
        DocumentManager::file_send_for_download($file, true, $filename);

        return null;
    }

    /**
     * Add a line to the csv file
     *
     * @param array Possible answers
     * @param array User's answers
     * @param mixed User ID or user details as string - Used as a string in the result string
     * @param boolean Whether to display user fields or not
     * @return string One line of the csv file
     */
    public static function export_complete_report_row_xls(
        $survey_data,
        $possible_options,
        $answers_of_user,
        $user,
        $display_extra_user_fields = false
    ) {
        $return = array();
        if ($survey_data['anonymous'] == 0) {
            if (intval($user) !== 0) {
                $userInfo = api_get_user_info($user);
                if ($userInfo) {
                    $user_displayed = $userInfo['complete_name_with_username'];
                } else {
                    $user_displayed = '-';
                }
                $return[] = $user_displayed;
            } else {
                $return[] = $user;
            }
        } else {
            $return[] = '-'; // The user column
        }

        if ($display_extra_user_fields) {
            //show user fields data, if any, for this user
            $user_fields_values = UserManager::get_extra_user_data(
                intval($user),
                false,
                false,
                false,
                true
            );
            foreach ($user_fields_values as $value) {
                $return[] = api_html_entity_decode(strip_tags($value), ENT_QUOTES);
            }
        }

        if (is_array($possible_options)) {
            foreach ($possible_options as $question_id => & $possible_option) {
                if (is_array($possible_option) && count($possible_option) > 0) {
                    foreach ($possible_option as $option_id => & $value) {
                        $my_answers_of_user = isset($answers_of_user[$question_id])
                            ? $answers_of_user[$question_id]
                            : [];
                        $key = array_keys($my_answers_of_user);
                        if (isset($key[0]) && substr($key[0], 0, 4) == 'open') {
                            $return[] = api_html_entity_decode(
                                strip_tags($answers_of_user[$question_id][$key[0]]['option_id']),
                                ENT_QUOTES
                            );
                        } elseif (!empty($answers_of_user[$question_id][$option_id])) {
                            if ($answers_of_user[$question_id][$option_id]['value'] != 0) {
                                $return[] = $answers_of_user[$question_id][$option_id]['value'];
                            } else {
                                $return[] = 'v';
                            }
                        } else {
                            $return[] = '';
                        }
                    }
                }
            }
        }

        return $return;
    }

    /**
     * This function displays the comparative report which
     * allows you to compare two questions
     * A comparative report creates a table where one question
     * is on the x axis and a second question is on the y axis.
     * In the intersection is the number of people who have
     * answered positive on both options.
     *
     * @return string HTML code
     *
     * @author Patrick Cool <patrick.cool@UGent.be>, Ghent University
     * @version February 2007
     */
    public static function display_comparative_report()
    {
        // Allowed question types for comparative report
        $allowed_question_types = array(
            'yesno',
            'multiplechoice',
            'multipleresponse',
            'dropdown',
            'percentage',
            'score'
        );

        $surveyId = isset($_GET['survey_id']) ? (int) $_GET['survey_id'] : 0;

        // Getting all the questions
        $questions = SurveyManager::get_questions($surveyId);

        // Actions bar
        echo '<div class="actions">';
        echo '<a href="'.api_get_path(WEB_CODE_PATH).'survey/reporting.php?survey_id='.$surveyId.'&'.api_get_cidreq()
            .'">'
            .Display::return_icon(
                'back.png',
                get_lang('BackTo').' '.get_lang('ReportingOverview'),
                [],
                ICON_SIZE_MEDIUM
            )
            .'</a>';
        echo '</div>';

        // Displaying an information message that only the questions with predefined answers can be used in a comparative report
        echo Display::return_message(get_lang('OnlyQuestionsWithPredefinedAnswers'), 'normal', false);

        $xAxis = isset($_GET['xaxis']) ? Security::remove_XSS($_GET['xaxis']) : '';
        $yAxis = isset($_GET['yaxis']) ? Security::remove_XSS($_GET['yaxis']) : '';

        $url = api_get_self().'?'.api_get_cidreq().'&action='.Security::remove_XSS($_GET['action'])
            .'&survey_id='.$surveyId.'&xaxis='.$xAxis.'&y='.$yAxis;

        $form = new FormValidator('compare', 'get', $url);
        $form->addHidden('action', Security::remove_XSS($_GET['action']));
        $form->addHidden('survey_id', $surveyId);
        $optionsX = ['----'];
        $optionsY = ['----'];
        $defaults = [];
        foreach ($questions as $key => & $question) {
            if (is_array($allowed_question_types)) {
                if (in_array($question['type'], $allowed_question_types)) {
                    //echo '<option value="'.$question['question_id'].'"';
                    if (isset($_GET['xaxis']) && $_GET['xaxis'] == $question['question_id']) {
                        $defaults['xaxis'] = $question['question_id'];
                    }

                    if (isset($_GET['yaxis']) && $_GET['yaxis'] == $question['question_id']) {
                        $defaults['yaxis'] = $question['question_id'];
                    }

                    $optionsX[$question['question_id']] = api_substr(strip_tags($question['question']), 0, 50);
                    $optionsY[$question['question_id']] = api_substr(strip_tags($question['question']), 0, 50);
                }
            }
        }

        $form->addSelect('xaxis', get_lang('SelectXAxis'), $optionsX);
        $form->addSelect('yaxis', get_lang('SelectYAxis'), $optionsY);

        $form->addButtonSearch(get_lang('CompareQuestions'));
        $form->setDefaults($defaults);
        $form->display();

        // Getting all the information of the x axis
        if (is_numeric($xAxis)) {
            $question_x = SurveyManager::get_question($xAxis);
        }

        // Getting all the information of the y axis
        if (is_numeric($yAxis)) {
            $question_y = SurveyManager::get_question($yAxis);
        }

        if (is_numeric($xAxis) && is_numeric($yAxis)) {
            // Getting the answers of the two questions
            $answers_x = self::get_answers_of_question_by_user($surveyId, $xAxis);
            $answers_y = self::get_answers_of_question_by_user($surveyId, $yAxis);

            // Displaying the table
            $tableHtml = '<table border="1" class="data_table">';
            $xOptions = array();
            // The header
            $tableHtml .= '<tr>';
            for ($ii = 0; $ii <= count($question_x['answers']); $ii++) {
                if ($ii == 0) {
                    $tableHtml .= '<th>&nbsp;</th>';
                } else {
                    if ($question_x['type'] == 'score') {
                        for ($x = 1; $x <= $question_x['maximum_score']; $x++) {
                            $tableHtml .= '<th>'.$question_x['answers'][($ii - 1)].'<br />'.$x.'</th>';
                        }
                        $x = '';
                    } else {
                        $tableHtml .= '<th>'.$question_x['answers'][($ii - 1)].'</th>';
                    }
                    $optionText = strip_tags($question_x['answers'][$ii - 1]);
                    $optionText = html_entity_decode($optionText);
                    array_push($xOptions, trim($optionText));
                }
            }
            $tableHtml .= '</tr>';
            $chartData = array();
            // The main part
            for ($ij = 0; $ij < count($question_y['answers']); $ij++) {
                $currentYQuestion = strip_tags($question_y['answers'][$ij]);
                $currentYQuestion = html_entity_decode($currentYQuestion);
                // The Y axis is a scoring question type so we have more rows than the options (actually options * maximum score)
                if ($question_y['type'] == 'score') {
                    for ($y = 1; $y <= $question_y['maximum_score']; $y++) {
                        $tableHtml .= '<tr>';
                        for ($ii = 0; $ii <= count($question_x['answers']); $ii++) {
                            if ($question_x['type'] == 'score') {
                                for ($x = 1; $x <= $question_x['maximum_score']; $x++) {
                                    if ($ii == 0) {
                                        $tableHtml .= '<th>'.$question_y['answers'][($ij)].' '.$y.'</th>';
                                        break;
                                    } else {
                                        $tableHtml .= '<td align="center">';
                                        $votes = self::comparative_check(
                                            $answers_x,
                                            $answers_y,
                                            $question_x['answersid'][($ii - 1)],
                                            $question_y['answersid'][($ij)],
                                            $x,
                                            $y
                                        );
                                        $tableHtml .= $votes;
                                        array_push(
                                            $chartData,
                                            array(
                                                'serie' => array($currentYQuestion, $xOptions[$ii - 1]),
                                                'option' => $x,
                                                'votes' => $votes
                                            )
                                        );
                                        $tableHtml .= '</td>';
                                    }
                                }
                            } else {
                                if ($ii == 0) {
                                    $tableHtml .= '<th>'.$question_y['answers'][$ij].' '.$y.'</th>';
                                } else {
                                    $tableHtml .= '<td align="center">';
                                    $votes = self::comparative_check(
                                        $answers_x,
                                        $answers_y,
                                        $question_x['answersid'][($ii - 1)],
                                        $question_y['answersid'][($ij)],
                                        0,
                                        $y
                                    );
                                    $tableHtml .= $votes;
                                    array_push(
                                        $chartData,
                                        array(
                                            'serie' => array($currentYQuestion, $xOptions[$ii - 1]),
                                            'option' => $y,
                                            'votes' => $votes
                                        )
                                    );
                                    $tableHtml .= '</td>';
                                }
                            }
                        }
                        $tableHtml .= '</tr>';
                    }
                } else {
                    // The Y axis is NOT a score question type so the number of rows = the number of options
                    $tableHtml .= '<tr>';
                    for ($ii = 0; $ii <= count($question_x['answers']); $ii++) {
                        if ($question_x['type'] == 'score') {
                            for ($x = 1; $x <= $question_x['maximum_score']; $x++) {
                                if ($ii == 0) {
                                    $tableHtml .= '<th>'.$question_y['answers'][$ij].'</th>';
                                    break;
                                } else {
                                    $tableHtml .= '<td align="center">';
                                    $votes = self::comparative_check(
                                        $answers_x,
                                        $answers_y,
                                        $question_x['answersid'][($ii - 1)],
                                        $question_y['answersid'][($ij)],
                                        $x,
                                        0
                                    );
                                    $tableHtml .= $votes;
                                    array_push(
                                        $chartData,
                                        array(
                                            'serie' => array($currentYQuestion, $xOptions[$ii - 1]),
                                            'option' => $x,
                                            'votes' => $votes
                                        )
                                    );
                                    $tableHtml .= '</td>';
                                }
                            }
                        } else {
                            if ($ii == 0) {
                                $tableHtml .= '<th>'.$question_y['answers'][($ij)].'</th>';
                            } else {
                                $tableHtml .= '<td align="center">';
                                $votes = self::comparative_check(
                                    $answers_x,
                                    $answers_y,
                                    $question_x['answersid'][($ii - 1)],
                                    $question_y['answersid'][($ij)]
                                );
                                $tableHtml .= $votes;
                                array_push(
                                    $chartData,
                                    array(
                                        'serie' => $xOptions[$ii - 1],
                                        'option' => $currentYQuestion,
                                        'votes' => $votes
                                    )
                                );
                                $tableHtml .= '</td>';
                            }
                        }
                    }
                    $tableHtml .= '</tr>';
                }
            }
            $tableHtml .= '</table>';
            echo '<div id="chartContainer" class="col-md-12">';
            echo self::drawChart($chartData, true);
            echo '</div>';
            echo $tableHtml;
        }
    }

    /**
     * Get all the answers of a question grouped by user
     *
     * @param integer $survey_id Survey ID
     * @param integer $question_id Question ID
     * @return array Array containing all answers of all users, grouped by user
     *
     * @author Patrick Cool <patrick.cool@UGent.be>, Ghent University
     * @version February 2007 - Updated March 2008
     */
    public static function get_answers_of_question_by_user($survey_id, $question_id)
    {
        $course_id = api_get_course_int_id();
        $table_survey_answer = Database::get_course_table(TABLE_SURVEY_ANSWER);

        $sql = "SELECT * FROM $table_survey_answer
                WHERE 
                  c_id = $course_id AND 
                  survey_id='".intval($survey_id)."' AND 
                  question_id='".intval($question_id)."'
                ORDER BY USER ASC";
        $result = Database::query($sql);
        $return = [];
        while ($row = Database::fetch_array($result)) {
            if ($row['value'] == 0) {
                $return[$row['user']][] = $row['option_id'];
            } else {
                $return[$row['user']][] = $row['option_id'].'*'.$row['value'];
            }
        }

        return $return;
    }

    /**
     * Count the number of users who answer positively on both options
     *
     * @param array All answers of the x axis
     * @param array All answers of the y axis
     * @param integer x axis value (= the option_id of the first question)
     * @param integer y axis value (= the option_id of the second question)
     * @return integer Number of users who have answered positively to both options
     *
     * @author Patrick Cool <patrick.cool@UGent.be>, Ghent University
     * @version February 2007
     */
    public static function comparative_check(
        $answers_x,
        $answers_y,
        $option_x,
        $option_y,
        $value_x = 0,
        $value_y = 0
    ) {
        if ($value_x == 0) {
            $check_x = $option_x;
        } else {
            $check_x = $option_x.'*'.$value_x;
        }
        if ($value_y == 0) {
            $check_y = $option_y;
        } else {
            $check_y = $option_y.'*'.$value_y;
        }

        $counter = 0;
        if (is_array($answers_x)) {
            foreach ($answers_x as $user => & $answers) {
                // Check if the user has given $option_x as answer
                if (in_array($check_x, $answers)) {
                    // Check if the user has given $option_y as an answer
                    if (!is_null($answers_y[$user]) &&
                        in_array($check_y, $answers_y[$user])
                    ) {
                        $counter++;
                    }
                }
            }
        }

        return $counter;
    }

    /**
     * Get all the information about the invitations of a certain survey
     *
     * @return array Lines of invitation [user, code, date, empty element]
     *
     * @author Patrick Cool <patrick.cool@UGent.be>, Ghent University
     * @version January 2007
     *
     * @todo use survey_id parameter instead of $_GET
     */
    public static function get_survey_invitations_data()
    {
        $course_id = api_get_course_int_id();
        // Database table definition
        $table_survey_invitation = Database::get_course_table(TABLE_SURVEY_INVITATION);
        $table_user = Database::get_main_table(TABLE_MAIN_USER);

        $sql = "SELECT
					survey_invitation.user as col1,
					survey_invitation.invitation_code as col2,
					survey_invitation.invitation_date as col3,
					'' as col4
                FROM $table_survey_invitation survey_invitation
                LEFT JOIN $table_user user
                ON survey_invitation.user = user.user_id
                WHERE
                    survey_invitation.c_id = $course_id AND
                    survey_invitation.survey_id = '".intval($_GET['survey_id'])."' AND
                    session_id='".api_get_session_id()."'  ";
        $res = Database::query($sql);
        $data = [];
        while ($row = Database::fetch_array($res)) {
            $data[] = $row;
        }

        return $data;
    }

    /**
     * Get the total number of survey invitations for a given survey (through $_GET['survey_id'])
     *
     * @return integer Total number of survey invitations
     *
     * @todo use survey_id parameter instead of $_GET
     *
     * @author Patrick Cool <patrick.cool@UGent.be>, Ghent University
     * @version January 2007
     */
    public static function get_number_of_survey_invitations()
    {
        $course_id = api_get_course_int_id();

        // Database table definition
        $table = Database::get_course_table(TABLE_SURVEY_INVITATION);

        $sql = "SELECT count(user) AS total
		        FROM $table
		        WHERE
                    c_id = $course_id AND
                    survey_id='".intval($_GET['survey_id'])."' AND
                    session_id='".api_get_session_id()."' ";
        $res = Database::query($sql);
        $row = Database::fetch_array($res, 'ASSOC');

        return $row['total'];
    }

    /**
     * Save the invitation mail
     *
     * @param string Text of the e-mail
     * @param integer Whether the mail contents are for invite mail (0, default) or reminder mail (1)
     *
     * @author Patrick Cool <patrick.cool@UGent.be>, Ghent University
     * @version January 2007
     */
    public static function save_invite_mail($mailtext, $mail_subject, $reminder = 0)
    {
        $course_id = api_get_course_int_id();
        // Database table definition
        $table_survey = Database::get_course_table(TABLE_SURVEY);

        // Reminder or not
        if ($reminder == 0) {
            $mail_field = 'invite_mail';
        } else {
            $mail_field = 'reminder_mail';
        }

        $sql = "UPDATE $table_survey SET
		        mail_subject='".Database::escape_string($mail_subject)."',
		        $mail_field = '".Database::escape_string($mailtext)."'
		        WHERE c_id = $course_id AND survey_id = '".intval($_GET['survey_id'])."'";
        Database::query($sql);
    }

    /**
     * This function saves all the invitations of course users
     * and additional users in the database
     * and sends the invitations by email
     *
     * @param $users_array Users $array array can be both a list of course uids AND a list of additional emailaddresses
     * @param $invitation_title Title $string of the invitation, used as the title of the mail
     * @param $invitation_text Text $string of the invitation, used as the text of the mail.
     *                         The text has to contain a **link** string or this will automatically be added to the end
     * @param int $reminder
     * @param bool $sendmail
     * @param int $remindUnAnswered
     * @return int
     * @internal param
     * @internal param
     * @internal param
     *                 The text has to contain a **link** string or this will automatically be added to the end
     * @author Patrick Cool <patrick.cool@UGent.be>, Ghent University
     * @author Julio Montoya - Adding auto-generated link support
     * @version January 2007
     */
    public static function saveInvitations(
        $users_array,
        $invitation_title,
        $invitation_text,
        $reminder = 0,
        $sendmail = false,
        $remindUnAnswered = 0
    ) {
        if (!is_array($users_array)) {
            // Should not happen

            return 0;
        }

        // Getting the survey information
        $survey_data = SurveyManager::get_survey($_GET['survey_id']);
        $survey_invitations = self::get_invitations($survey_data['survey_code']);
        $already_invited = self::get_invited_users($survey_data['code']);

        // Remind unanswered is a special version of remind all reminder
        $exclude_users = array();
        if ($remindUnAnswered == 1) { // Remind only unanswered users
            $reminder = 1;
            $exclude_users = SurveyManager::get_people_who_filled_survey($_GET['survey_id']);
        }

        $counter = 0; // Nr of invitations "sent" (if sendmail option)
        $course_id = api_get_course_int_id();
        $session_id = api_get_session_id();
        $result = CourseManager::separateUsersGroups($users_array);

        $groupList = $result['groups'];
        $users_array = $result['users'];

        foreach ($groupList as $groupId) {
            $userGroupList = GroupManager::getStudents($groupId);
            $userGroupIdList = array_column($userGroupList, 'user_id');
            $users_array = array_merge($users_array, $userGroupIdList);

            $params = array(
                'c_id' => $course_id,
                'session_id' => $session_id,
                'group_id' => $groupId,
                'survey_code' => $survey_data['code']
            );

            $invitationExists = self::invitationExists(
                $course_id,
                $session_id,
                $groupId,
                $survey_data['code']
            );
            if (empty($invitationExists)) {
                self::save_invitation($params);
            }
        }

        $users_array = array_unique($users_array);

        foreach ($users_array as $key => $value) {
            if (!isset($value) || $value == '') {
                continue;
            }

            // Skip user if reminding only unanswered people
            if (in_array($value, $exclude_users)) {
                continue;
            }

            // Get the unique invitation code if we already have it
            if ($reminder == 1 && array_key_exists($value, $survey_invitations)) {
                $invitation_code = $survey_invitations[$value]['invitation_code'];
            } else {
                $invitation_code = md5($value.microtime());
            }
            $new_user = false; // User not already invited
            // Store the invitation if user_id not in $already_invited['course_users'] OR email is not in $already_invited['additional_users']
            $addit_users_array = isset($already_invited['additional_users']) && !empty($already_invited['additional_users'])
                    ? explode(';', $already_invited['additional_users'])
                    : array();
            $my_alredy_invited = $already_invited['course_users'] == null ? array() : $already_invited['course_users'];
            if ((is_numeric($value) && !in_array($value, $my_alredy_invited)) ||
                (!is_numeric($value) && !in_array($value, $addit_users_array))
            ) {
                $new_user = true;
                if (!array_key_exists($value, $survey_invitations)) {
                    $params = array(
                        'c_id' => $course_id,
                        'session_id' => $session_id,
                        'user' => $value,
                        'survey_code' => $survey_data['code'],
                        'invitation_code' => $invitation_code,
                        'invitation_date' => api_get_utc_datetime()
                    );
                    self::save_invitation($params);
                }
            }

            // Send the email if checkboxed
            if (($new_user || $reminder == 1) && $sendmail) {
                // Make a change for absolute url
                if (isset($invitation_text)) {
                    $invitation_text = api_html_entity_decode($invitation_text, ENT_QUOTES);
                    $invitation_text = str_replace('src="../../', 'src="'.api_get_path(WEB_PATH), $invitation_text);
                    $invitation_text = trim(stripslashes($invitation_text));
                }
                self::send_invitation_mail(
                    $value,
                    $invitation_code,
                    $invitation_title,
                    $invitation_text
                );
                $counter++;
            }
        }

        return $counter; // Number of invitations sent
    }

    /**
     * @param $params
     * @return bool|int
     */
    public static function save_invitation($params)
    {
        // Database table to store the invitations data
        $table = Database::get_course_table(TABLE_SURVEY_INVITATION);
        if (!empty($params['c_id']) &&
            (!empty($params['user']) || !empty($params['group_id'])) &&
            !empty($params['survey_code'])
        ) {
            $insertId = Database::insert($table, $params);
            if ($insertId) {
                $sql = "UPDATE $table 
                        SET survey_invitation_id = $insertId
                        WHERE iid = $insertId";
                Database::query($sql);
            }

            return $insertId;
        }

        return false;
    }

    /**
     * @param int $courseId
     * @param int $sessionId
     * @param int $groupId
     * @param string $surveyCode
     * @return int
     */
    public static function invitationExists($courseId, $sessionId, $groupId, $surveyCode)
    {
        $table = Database::get_course_table(TABLE_SURVEY_INVITATION);
        $courseId = intval($courseId);
        $sessionId = intval($sessionId);
        $groupId = intval($groupId);
        $surveyCode = Database::escape_string($surveyCode);

        $sql = "SELECT survey_invitation_id FROM $table
                WHERE
                    c_id = $courseId AND
                    session_id = $sessionId AND
                    group_id = $groupId AND
                    survey_code = '$surveyCode'
                ";
        $result = Database::query($sql);

        return Database::num_rows($result);
    }

    /**
     * Send the invitation by mail.
     *
     * @param int invitedUser - the userId (course user) or emailaddress of additional user
     * $param string $invitation_code - the unique invitation code for the URL
     * @return void
     */
    public static function send_invitation_mail(
        $invitedUser,
        $invitation_code,
        $invitation_title,
        $invitation_text
    ) {
        $_user = api_get_user_info();
        $_course = api_get_course_info();

        // Replacing the **link** part with a valid link for the user
        $survey_link = api_get_path(WEB_CODE_PATH).'survey/fillsurvey.php?course='.$_course['code'].'&invitationcode='
            .$invitation_code;
        $text_link = '<a href="'.$survey_link.'">'.get_lang('ClickHereToAnswerTheSurvey')."</a><br />\r\n<br />\r\n"
            .get_lang('OrCopyPasteTheFollowingUrl')." <br />\r\n ".$survey_link;

        $replace_count = 0;
        $full_invitation_text = api_str_ireplace('**link**', $text_link, $invitation_text, $replace_count);
        if ($replace_count < 1) {
            $full_invitation_text = $full_invitation_text."<br />\r\n<br />\r\n".$text_link;
        }

        // Sending the mail
        $sender_name = api_get_person_name($_user['firstName'], $_user['lastName'], null, PERSON_NAME_EMAIL_ADDRESS);
        $sender_email = $_user['mail'];
        $sender_user_id = api_get_user_id();

        $replyto = array();
        if (api_get_setting('survey_email_sender_noreply') == 'noreply') {
            $noreply = api_get_setting('noreply_email_address');
            if (!empty($noreply)) {
                $replyto['Reply-to'] = $noreply;
                $sender_name = $noreply;
                $sender_email = $noreply;
                $sender_user_id = null;
            }
        }

        // Optionally: finding the e-mail of the course user
        if (is_numeric($invitedUser)) {
            MessageManager::send_message(
                $invitedUser,
                $invitation_title,
                $full_invitation_text,
                [],
                [],
                null,
                null,
                null,
                null,
                $sender_user_id
            );
        } else {
            /** @todo check if the address is a valid email */
            $recipient_email = $invitedUser;
            @api_mail_html(
                '',
                $recipient_email,
                $invitation_title,
                $full_invitation_text,
                $sender_name,
                $sender_email,
                $replyto
            );
        }
    }

    /**
     * This function recalculates the number of users who have been invited and updates the survey table with this
     * value.
     *
     * @param string Survey code
     * @return void
     * @author Patrick Cool <patrick.cool@UGent.be>, Ghent University
     * @version January 2007
     */
    public static function update_count_invited($survey_code)
    {
        $course_id = api_get_course_int_id();

        // Database table definition
        $table_survey_invitation = Database::get_course_table(TABLE_SURVEY_INVITATION);
        $table_survey = Database::get_course_table(TABLE_SURVEY);

        // Counting the number of people that are invited
        $sql = "SELECT count(user) as total
                FROM $table_survey_invitation
		        WHERE
		            c_id = $course_id AND
		            survey_code = '".Database::escape_string($survey_code)."' AND
		            user <> ''
                ";
        $result = Database::query($sql);
        $row = Database::fetch_array($result);
        $total_invited = $row['total'];

        // Updating the field in the survey table
        $sql = "UPDATE $table_survey
		        SET invited = '".Database::escape_string($total_invited)."'
		        WHERE
		            c_id = $course_id AND
		            code = '".Database::escape_string($survey_code)."'
                ";
        Database::query($sql);
    }

    /**
     * This function gets all the invited users for a given survey code.
     *
     * @param string Survey code
     * @param string optional - course database
     * @return array Array containing the course users and additional users (non course users)
     *
     * @todo consider making $defaults['additional_users'] also an array
     *
     * @author Patrick Cool <patrick.cool@UGent.be>, Ghent University
     * @author Julio Montoya, adding c_id fixes - Dec 2012
     * @version January 2007
     */
    public static function get_invited_users($survey_code, $course_code = '', $session_id = 0)
    {
        if (!empty($course_code)) {
            $course_info = api_get_course_info($course_code);
            $course_id = $course_info['real_id'];
        } else {
            $course_id = api_get_course_int_id();
        }

        if (empty($session_id)) {
            $session_id = api_get_session_id();
        }

        $table_survey_invitation = Database::get_course_table(TABLE_SURVEY_INVITATION);
        $table_user = Database::get_main_table(TABLE_MAIN_USER);

        // Selecting all the invitations of this survey AND the additional emailaddresses (the left join)
        $order_clause = api_sort_by_first_name() ? ' ORDER BY firstname, lastname' : ' ORDER BY lastname, firstname';
        $sql = "SELECT user, group_id
				FROM $table_survey_invitation as table_invitation
				WHERE
				    table_invitation.c_id = $course_id AND
                    survey_code='".Database::escape_string($survey_code)."' AND
                    session_id = $session_id
                ";

        $defaults = array();
        $defaults['course_users'] = array();
        $defaults['additional_users'] = array(); // Textarea
        $defaults['users'] = array(); // user and groups

        $result = Database::query($sql);
        while ($row = Database::fetch_array($result)) {
            if (is_numeric($row['user'])) {
                $defaults['course_users'][] = $row['user'];
                $defaults['users'][] = 'USER:'.$row['user'];
            } else {
                if (!empty($row['user'])) {
                    $defaults['additional_users'][] = $row['user'];
                }
            }

            if (isset($row['group_id']) && !empty($row['group_id'])) {
                $defaults['users'][] = 'GROUP:'.$row['group_id'];
            }
        }

        if (!empty($defaults['course_users'])) {
            $user_ids = implode("','", $defaults['course_users']);
            $sql = "SELECT user_id FROM $table_user WHERE user_id IN ('$user_ids') $order_clause";
            $result = Database::query($sql);
            $fixed_users = array();
            while ($row = Database::fetch_array($result)) {
                $fixed_users[] = $row['user_id'];
            }
            $defaults['course_users'] = $fixed_users;
        }

        if (!empty($defaults['additional_users'])) {
            $defaults['additional_users'] = implode(';', $defaults['additional_users']);
        }

        return $defaults;
    }

    /**
     * Get all the invitations
     *
     * @param string Survey code
     * @return array Database rows matching the survey code
     *
     * @author Patrick Cool <patrick.cool@UGent.be>, Ghent University
     * @version September 2007
     */
    public static function get_invitations($survey_code)
    {
        $course_id = api_get_course_int_id();
        // Database table definition
        $table_survey_invitation = Database::get_course_table(TABLE_SURVEY_INVITATION);

        $sql = "SELECT * FROM $table_survey_invitation
		        WHERE
		            c_id = $course_id AND
		            survey_code = '".Database::escape_string($survey_code)."'";
        $result = Database::query($sql);
        $return = array();
        while ($row = Database::fetch_array($result)) {
            $return[$row['user']] = $row;
        }

        return $return;
    }

    /**
     * This function displays the form for searching a survey
     *
     * @return void (direct output)
     *
     * @author Patrick Cool <patrick.cool@UGent.be>, Ghent University
     * @version January 2007
     *
     * @todo use quickforms
     * @todo consider moving this to surveymanager.inc.lib.php
     */
    public static function display_survey_search_form()
    {
        $url = api_get_path(WEB_CODE_PATH).'survey/survey_list.php?search=advanced&'.api_get_cidreq();
        $form = new FormValidator('search', 'get', $url);
        $form->addHeader(get_lang('SearchASurvey'));
        $form->addText('keyword_title', get_lang('Title'));
        $form->addText('keyword_code', get_lang('Code'));
        $form->addSelectLanguage('keyword_language', get_lang('Language'));
        $form->addHidden('cidReq', api_get_course_id());
        $form->addButtonSearch(get_lang('Search'), 'do_search');
        $form->display();
    }

    /**
     * Show table only visible by DRH users
     */
    public static function displaySurveyListForDrh()
    {
        $parameters = array();
        $parameters['cidReq'] = api_get_course_id();

        // Create a sortable table with survey-data
        $table = new SortableTable(
            'surveys',
            'get_number_of_surveys',
            'get_survey_data_drh',
            2
        );
        $table->set_additional_parameters($parameters);
        $table->set_header(0, '', false);
        $table->set_header(1, get_lang('SurveyName'));
        $table->set_header(2, get_lang('SurveyCode'));
        $table->set_header(3, get_lang('NumberOfQuestions'));
        $table->set_header(4, get_lang('Author'));
        $table->set_header(5, get_lang('AvailableFrom'));
        $table->set_header(6, get_lang('AvailableUntil'));
        $table->set_header(7, get_lang('Invite'));
        $table->set_header(8, get_lang('Anonymous'));

        if (api_get_configuration_value('allow_mandatory_survey')) {
            $table->set_header(9, get_lang('IsMandatory'));
            $table->set_header(10, get_lang('Modify'), false, 'width="150"');
            $table->set_column_filter(9, 'anonymous_filter');
            $table->set_column_filter(10, 'modify_filter_drh');
        } else {
            $table->set_header(9, get_lang('Modify'), false, 'width="150"');
            $table->set_column_filter(9, 'modify_filter_drh');
        }

        $table->set_column_filter(8, 'anonymous_filter');
        $table->display();
    }

    /**
     * This function displays the sortable table with all the surveys
     *
     * @return void (direct output)
     *
     * @author Patrick Cool <patrick.cool@UGent.be>, Ghent University
     * @version January 2007
     */
    public static function display_survey_list()
    {
        $parameters = array();
        $parameters['cidReq'] = api_get_course_id();
        if (isset($_GET['do_search']) && $_GET['do_search']) {
            $message = get_lang('DisplaySearchResults').'<br />';
            $message .= '<a href="'.api_get_self().'?'.api_get_cidreq().'">'.get_lang('DisplayAll').'</a>';
            echo Display::return_message($message, 'normal', false);
        }

        // Create a sortable table with survey-data
        $table = new SortableTable(
            'surveys',
            'get_number_of_surveys',
            'get_survey_data',
            2
        );
        $table->set_additional_parameters($parameters);
        $table->set_header(0, '', false);
        $table->set_header(1, get_lang('SurveyName'));
        $table->set_header(2, get_lang('SurveyCode'));
        $table->set_header(3, get_lang('NumberOfQuestions'));
        $table->set_header(4, get_lang('Author'));
        //$table->set_header(5, get_lang('Language'));
        //$table->set_header(6, get_lang('Shared'));
        $table->set_header(5, get_lang('AvailableFrom'));
        $table->set_header(6, get_lang('AvailableUntil'));
        $table->set_header(7, get_lang('Invite'));
        $table->set_header(8, get_lang('Anonymous'));

        if (api_get_configuration_value('allow_mandatory_survey')) {
            $table->set_header(9, get_lang('IsMandatory'));
            $table->set_header(10, get_lang('Modify'), false, 'width="150"');
            $table->set_column_filter(9, 'anonymous_filter');
            $table->set_column_filter(10, 'modify_filter');
        } else {
            $table->set_header(9, get_lang('Modify'), false, 'width="150"');
            $table->set_column_filter(9, 'modify_filter');
        }

        $table->set_column_filter(8, 'anonymous_filter');
        $table->set_form_actions(array('delete' => get_lang('DeleteSurvey')));
        $table->display();
    }

    /**
     * Survey list for coach
     */
    public static function display_survey_list_for_coach()
    {
        $parameters = array();
        $parameters['cidReq'] = api_get_course_id();
        if (isset($_GET['do_search'])) {
            $message = get_lang('DisplaySearchResults').'<br />';
            $message .= '<a href="'.api_get_self().'?'.api_get_cidreq().'">'.get_lang('DisplayAll').'</a>';
            echo Display::return_message($message, 'normal', false);
        }

        // Create a sortable table with survey-data
        $table = new SortableTable(
            'surveys_coach',
            'get_number_of_surveys_for_coach',
            'get_survey_data_for_coach',
            2
        );
        $table->set_additional_parameters($parameters);
        $table->set_header(0, '', false);
        $table->set_header(1, get_lang('SurveyName'));
        $table->set_header(2, get_lang('SurveyCode'));
        $table->set_header(3, get_lang('NumberOfQuestions'));
        $table->set_header(4, get_lang('Author'));
        //$table->set_header(5, get_lang('Language'));
        //$table->set_header(6, get_lang('Shared'));
        $table->set_header(5, get_lang('AvailableFrom'));
        $table->set_header(6, get_lang('AvailableUntil'));
        $table->set_header(7, get_lang('Invite'));
        $table->set_header(8, get_lang('Anonymous'));

        if (api_get_configuration_value('allow_mandatory_survey')) {
            $table->set_header(9, get_lang('Modify'), false, 'width="130"');
            $table->set_header(10, get_lang('Modify'), false, 'width="130"');
            $table->set_column_filter(9, 'anonymous_filter');
            $table->set_column_filter(10, 'modify_filter_for_coach');
        } else {
            $table->set_header(9, get_lang('Modify'), false, 'width="130"');
            $table->set_column_filter(9, 'modify_filter_for_coach');
        }

        $table->set_column_filter(8, 'anonymous_filter');
        $table->display();
    }

    /**
     * Check if the hide_survey_edition configurations setting is enabled
     * @param string $surveyCode
     * @return bool
     */
    public static function checkHideEditionToolsByCode($surveyCode)
    {
        $hideSurveyEdition = api_get_configuration_value('hide_survey_edition');

        if (false === $hideSurveyEdition) {
            return false;
        }

        if ('*' === $hideSurveyEdition['codes']) {
            return true;
        }

        if (in_array($surveyCode, $hideSurveyEdition['codes'])) {
            return true;
        }

        return false;
    }

    /**
     * This function changes the modify column of the sortable table
     *
     * @param integer $survey_id the id of the survey
     * @param bool $drh
     * @return string html code that are the actions that can be performed on any survey
     *
     * @author Patrick Cool <patrick.cool@UGent.be>, Ghent University
     * @version January 2007
     */
    public static function modify_filter($survey_id, $drh = false)
    {
        /** @var CSurvey $survey */
        $survey = Database::getManager()->find('ChamiloCourseBundle:CSurvey', $survey_id);
        $hideSurveyEdition = self::checkHideEditionToolsByCode($survey->getCode());

        if ($hideSurveyEdition) {
            return '';
        }

        $survey_id = $survey->getSurveyId();
        $return = '';
        $hideReportingButton = api_get_configuration_value('hide_survey_reporting_button');

        $reportingLink = Display::url(
            Display::return_icon('stats.png', get_lang('Reporting'), [], ICON_SIZE_SMALL),
            api_get_path(WEB_CODE_PATH).'survey/reporting.php?'.api_get_cidreq().'&survey_id='.$survey_id
        );

        if ($drh) {
            return $hideReportingButton ? '-' : $reportingLink;
        }

        // Coach can see that only if the survey is in his session
        if (api_is_allowed_to_edit() ||
            api_is_element_in_the_session(TOOL_SURVEY, $survey_id)
        ) {
            $return .= '<a href="'.api_get_path(WEB_CODE_PATH).'survey/create_new_survey.php?'.api_get_cidreq()
                .'&action=edit&survey_id='.$survey_id.'">'
                .Display::return_icon('edit.png', get_lang('Edit'), '', ICON_SIZE_SMALL)
                .'</a>';
            if (SurveyManager::survey_generation_hash_available()) {
                $return .= Display::url(
                    Display::return_icon('new_link.png', get_lang('GenerateSurveyAccessLink'), '', ICON_SIZE_SMALL),
                    api_get_path(WEB_CODE_PATH).'survey/generate_link.php?survey_id='.$survey_id.'&'.api_get_cidreq()
                );
            }
            $return .= Display::url(
                Display::return_icon('copy.png', get_lang('DuplicateSurvey'), '', ICON_SIZE_SMALL),
                'survey_list.php?action=copy_survey&survey_id='.$survey_id.'&'.api_get_cidreq()
            );

            $return .= ' <a href="'.api_get_path(WEB_CODE_PATH).'survey/survey_list.php?'.api_get_cidreq()
                .'&action=empty&survey_id='.$survey_id.'" onclick="javascript: if(!confirm(\''
                .addslashes(api_htmlentities(get_lang("EmptySurvey").'?')).'\')) return false;">'
                .Display::return_icon('clean.png', get_lang('EmptySurvey'), '', ICON_SIZE_SMALL)
                .'</a>&nbsp;';
        }
        $return .= '<a href="'.api_get_path(WEB_CODE_PATH).'survey/preview.php?'.api_get_cidreq().'&survey_id='
            .$survey_id.'">'
            .Display::return_icon('preview_view.png', get_lang('Preview'), '', ICON_SIZE_SMALL)
            .'</a>&nbsp;';
        $return .= '<a href="'.api_get_path(WEB_CODE_PATH).'survey/survey_invite.php?'.api_get_cidreq().'&survey_id='
            .$survey_id.'">'
            .Display::return_icon('mail_send.png', get_lang('Publish'), '', ICON_SIZE_SMALL)
            .'</a>&nbsp;';
        $return .= $hideReportingButton ? '' : $reportingLink;

        if (api_is_allowed_to_edit() ||
            api_is_element_in_the_session(TOOL_SURVEY, $survey_id)
        ) {
            $return .= '<a href="'.api_get_path(WEB_CODE_PATH).'survey/survey_list.php?'.api_get_cidreq()
                .'&action=delete&survey_id='.$survey_id.'" onclick="javascript: if(!confirm(\''
                .addslashes(api_htmlentities(get_lang("DeleteSurvey").'?', ENT_QUOTES)).'\')) return false;">'
                .Display::return_icon('delete.png', get_lang('Delete'), '', ICON_SIZE_SMALL)
                .'</a>&nbsp;';
        }

        return $return;
    }

    public static function modify_filter_for_coach($survey_id)
    {
        $survey_id = Security::remove_XSS($survey_id);
        //$return = '<a href="create_new_survey.php?'.api_get_cidreq().'&action=edit&survey_id='.$survey_id.'">'.Display::return_icon('edit.gif', get_lang('Edit')).'</a>';
        //$return .= '<a href="survey_list.php?'.api_get_cidreq().'&action=delete&survey_id='.$survey_id.'" onclick="javascript:if(!confirm(\''.addslashes(api_htmlentities(get_lang("DeleteSurvey").'?', ENT_QUOTES)).'\')) return false;">'.Display::return_icon('delete.gif', get_lang('Delete')).'</a>';
        //$return .= '<a href="create_survey_in_another_language.php?id_survey='.$survey_id.'">'.Display::return_icon('copy.gif', get_lang('Copy')).'</a>';
        //$return .= '<a href="survey.php?survey_id='.$survey_id.'">'.Display::return_icon('add.gif', get_lang('Add')).'</a>';
        $return = '<a href="'.api_get_path(WEB_CODE_PATH).'survey/preview.php?'.api_get_cidreq()
            .'&survey_id='.$survey_id.'">'
            .Display::return_icon('preview_view.png', get_lang('Preview'), '', ICON_SIZE_SMALL).'</a>&nbsp;';
        $return .= '<a href="'.api_get_path(WEB_CODE_PATH).'survey/survey_invite.php?'.api_get_cidreq().'&survey_id='
            .$survey_id.'">'.Display::return_icon('mail_send.png', get_lang('Publish'), '', ICON_SIZE_SMALL)
            .'</a>&nbsp;';
        $return .= '<a href="'.api_get_path(WEB_CODE_PATH).'survey/survey_list.php?'.api_get_cidreq()
            .'&action=empty&survey_id='.$survey_id.'" onclick="javascript: if(!confirm(\''
            .addslashes(api_htmlentities(get_lang("EmptySurvey").'?', ENT_QUOTES)).'\')) return false;">'
            .Display::return_icon('clean.png', get_lang('EmptySurvey'), '', ICON_SIZE_SMALL).'</a>&nbsp;';

        return $return;
    }

    /**
     * Returns "yes" when given parameter is one, "no" for any other value
     * @param integer Whether anonymous or not
     * @return string "Yes" or "No" in the current language
     */
    public static function anonymous_filter($anonymous)
    {
        if ($anonymous == 1) {
            return get_lang('Yes');
        } else {
            return get_lang('No');
        }
    }

    /**
     * This function handles the search restriction for the SQL statements
     *
     * @return string Part of a SQL statement or false on error
     *
     * @author Patrick Cool <patrick.cool@UGent.be>, Ghent University
     * @version January 2007
     */
    public static function survey_search_restriction()
    {
        if (isset($_GET['do_search'])) {
            if ($_GET['keyword_title'] != '') {
                $search_term[] = 'title like "%" \''.Database::escape_string($_GET['keyword_title']).'\' "%"';
            }
            if ($_GET['keyword_code'] != '') {
                $search_term[] = 'code =\''.Database::escape_string($_GET['keyword_code']).'\'';
            }
            if ($_GET['keyword_language'] != '%') {
                $search_term[] = 'lang =\''.Database::escape_string($_GET['keyword_language']).'\'';
            }
            $my_search_term = ($search_term == null) ? array() : $search_term;
            $search_restriction = implode(' AND ', $my_search_term);

            return $search_restriction;
        } else {
            return false;
        }
    }

    /**
     * This function calculates the total number of surveys
     *
     * @return integer Total number of surveys
     *
     * @author Patrick Cool <patrick.cool@UGent.be>, Ghent University
     * @version January 2007
     */
    public static function get_number_of_surveys()
    {
        $table_survey = Database::get_course_table(TABLE_SURVEY);
        $course_id = api_get_course_int_id();

        $search_restriction = self::survey_search_restriction();
        if ($search_restriction) {
            $search_restriction = 'WHERE c_id = '.$course_id.' AND '.$search_restriction;
        } else {
            $search_restriction = "WHERE c_id = $course_id";
        }
        $sql = "SELECT count(survey_id) AS total_number_of_items
		        FROM ".$table_survey.' '.$search_restriction;
        $res = Database::query($sql);
        $obj = Database::fetch_object($res);

        return $obj->total_number_of_items;
    }

    /**
     * @return int
     */
    public static function get_number_of_surveys_for_coach()
    {
        $survey_tree = new SurveyTree();

        return count($survey_tree->surveylist);
    }

    /**
     * This function gets all the survey data that is to be displayed in the sortable table
     *
     * @param int $from
     * @param int $number_of_items
     * @param int $column
     * @param string $direction
     * @param bool $isDrh
     * @return array
     *
     * @author Patrick Cool <patrick.cool@UGent.be>, Ghent University
     * @author Julio Montoya <gugli100@gmail.com>, Beeznest - Adding intvals
     * @version January 2007
     */
    public static function get_survey_data(
        $from,
        $number_of_items,
        $column,
        $direction,
        $isDrh = false
    ) {
        $table_survey = Database::get_course_table(TABLE_SURVEY);
        $table_user = Database::get_main_table(TABLE_MAIN_USER);
        $table_survey_question = Database::get_course_table(TABLE_SURVEY_QUESTION);
        $mandatoryAllowed = api_get_configuration_value('allow_mandatory_survey');
        $_user = api_get_user_info();

        // Searching
        $search_restriction = self::survey_search_restriction();
        if ($search_restriction) {
            $search_restriction = ' AND '.$search_restriction;
        }
        $from = intval($from);
        $number_of_items = intval($number_of_items);
        $column = intval($column);
        if (!in_array(strtolower($direction), array('asc', 'desc'))) {
            $direction = 'asc';
        }

        // Condition for the session
        $session_id = api_get_session_id();
        $condition_session = api_get_session_condition($session_id);
        $course_id = api_get_course_int_id();

        $sql = "
            SELECT
                survey.survey_id AS col0,
                survey.title AS col1,
                survey.code AS col2,
                count(survey_question.question_id) AS col3,
        "
            .(api_is_western_name_order()
                ? "CONCAT(user.firstname, ' ', user.lastname)"
                : "CONCAT(user.lastname, ' ', user.firstname)")
            ."	AS col4,
                survey.avail_from AS col5,
                survey.avail_till AS col6,
                survey.invited AS col7,
                survey.anonymous AS col8,
                survey.survey_id AS col9,
                survey.session_id AS session_id,
                survey.answered,
                survey.invited
            FROM $table_survey survey
            LEFT JOIN $table_survey_question survey_question
            ON (survey.survey_id = survey_question.survey_id AND survey_question.c_id = $course_id)
            LEFT JOIN $table_user user
            ON (survey.author = user.user_id)
            WHERE survey.c_id = $course_id
            $search_restriction
            $condition_session 
            GROUP BY survey.survey_id
            ORDER BY col$column $direction 
            LIMIT $from,$number_of_items
        ";

        $res = Database::query($sql);
        $surveys = array();
        $array = array();
        $efv = new ExtraFieldValue('survey');

        while ($survey = Database::fetch_array($res)) {
            $array[0] = $survey[0];

            if (self::checkHideEditionToolsByCode($survey['col2'])) {
                $array[1] = $survey[1];
            } else {
                $array[1] = Display::url(
                    $survey[1],
                    api_get_path(WEB_CODE_PATH).'survey/survey.php?survey_id='.$survey[0].'&'.api_get_cidreq()
                );
            }

            // Validation when belonging to a session
            $session_img = api_get_session_image($survey['session_id'], $_user['status']);
            $array[2] = $survey[2].$session_img;
            $array[3] = $survey[3];
            $array[4] = $survey[4];
            $array[5] = $survey[5];
            $array[6] = $survey[6];
            $array[7] =
                Display::url(
                    $survey['answered'],
                    api_get_path(WEB_CODE_PATH).'survey/survey_invitation.php?view=answered&survey_id='.$survey[0].'&'
                        .api_get_cidreq()
                ).' / '.
                Display::url(
                    $survey['invited'],
                    api_get_path(WEB_CODE_PATH).'survey/survey_invitation.php?view=invited&survey_id='.$survey[0].'&'
                        .api_get_cidreq()
                );

            $array[8] = $survey[8];

            if ($mandatoryAllowed) {
                $efvMandatory = $efv->get_values_by_handler_and_field_variable(
                    $survey[9],
                    'is_mandatory'
                );

                $array[9] = $efvMandatory ? $efvMandatory['value'] : 0;
                $array[10] = $survey[9];
            } else {
                $array[9] = $survey[9];
            }

            if ($isDrh) {
                $array[1] = $survey[1];
                $array[7] = strip_tags($array[7]);
            }

            $surveys[] = $array;
        }

        return $surveys;
    }

    /**
     * @param $from
     * @param $number_of_items
     * @param $column
     * @param $direction
     * @return array
     */
    public static function get_survey_data_for_coach($from, $number_of_items, $column, $direction)
    {
        $mandatoryAllowed = api_get_configuration_value('allow_mandatory_survey');
        $survey_tree = new SurveyTree();
        //$last_version_surveys = $survey_tree->get_last_children_from_branch($survey_tree->surveylist);
        $last_version_surveys = $survey_tree->surveylist;
        $list = array();
        foreach ($last_version_surveys as & $survey) {
            $list[] = $survey['id'];
        }
        if (count($list) > 0) {
            $list_condition = " AND survey.survey_id IN (".implode(',', $list).") ";
        } else {
            $list_condition = '';
        }

        $from = intval($from);
        $number_of_items = intval($number_of_items);
        $column = intval($column);
        if (!in_array(strtolower($direction), array('asc', 'desc'))) {
            $direction = 'asc';
        }

        $table_survey = Database::get_course_table(TABLE_SURVEY);
        $table_survey_question = Database::get_course_table(TABLE_SURVEY_QUESTION);
        $table_user = Database::get_main_table(TABLE_MAIN_USER);
        $course_id = api_get_course_int_id();
        $efv = new ExtraFieldValue('survey');

        $sql = "
            SELECT 
            survey.survey_id AS col0, 
                survey.title AS col1, 
                survey.code AS col2, 
                count(survey_question.question_id) AS col3, 
        "
            .(api_is_western_name_order()
                ? "CONCAT(user.firstname, ' ', user.lastname)"
                : "CONCAT(user.lastname, ' ', user.firstname)")
            ."	AS col4,
                survey.avail_from AS col5,
                survey.avail_till AS col6,
                CONCAT('<a href=\"survey_invitation.php?view=answered&survey_id=',survey.survey_id,'\">',survey.answered,'</a> / <a href=\"survey_invitation.php?view=invited&survey_id=',survey.survey_id,'\">',survey.invited, '</a>') AS col7,
                survey.anonymous AS col8,
                survey.survey_id AS col9
            FROM $table_survey survey
            LEFT JOIN $table_survey_question survey_question
            ON (survey.survey_id = survey_question.survey_id AND survey.c_id = survey_question.c_id),
            $table_user user
            WHERE survey.author = user.user_id AND survey.c_id = $course_id $list_condition
        ";
        $sql .= " GROUP BY survey.survey_id";
        $sql .= " ORDER BY col$column $direction ";
        $sql .= " LIMIT $from,$number_of_items";

        $res = Database::query($sql);
        $surveys = array();
        while ($survey = Database::fetch_array($res)) {
            if ($mandatoryAllowed) {
                $survey['col10'] = $survey['col9'];
                $efvMandatory = $efv->get_values_by_handler_and_field_variable(
                    $survey['col9'],
                    'is_mandatory'
                );
                $survey['col9'] = $efvMandatory['value'];
            }
            $surveys[] = $survey;
        }

        return $surveys;
    }

    /**
     * Display all the active surveys for the given course user
     *
     * @param int $user_id
     *
     * @author Patrick Cool <patrick.cool@UGent.be>, Ghent University
     * @version April 2007
     */
    public static function getSurveyList($user_id)
    {
        $_course = api_get_course_info();
        $course_id = $_course['real_id'];
        $user_id = intval($user_id);
        $sessionId = api_get_session_id();
        $mandatoryAllowed = api_get_configuration_value('allow_mandatory_survey');

        // Database table definitions
        $table_survey_question = Database::get_course_table(TABLE_SURVEY_QUESTION);
        $table_survey_invitation = Database::get_course_table(TABLE_SURVEY_INVITATION);
        $table_survey = Database::get_course_table(TABLE_SURVEY);

        $sql = "SELECT question_id
                FROM $table_survey_question
                WHERE c_id = $course_id";
        $result = Database::query($sql);

        $all_question_id = array();
        while ($row = Database::fetch_array($result, 'ASSOC')) {
            $all_question_id[] = $row;
        }

        echo '<table id="list-survey" class="table ">';
        echo '<thead>';
        echo '<tr>';
        echo '	<th>'.get_lang('SurveyName').'</th>';
        echo '	<th class="text-center">'.get_lang('Anonymous').'</th>';
        if ($mandatoryAllowed) {
            echo '<th class="text-center">'.get_lang('IsMandatory').'</th>';
        }
        echo '</tr>';
        echo '</thead>';
        echo '<tbody>';

        $now = api_get_utc_datetime();

        $sql = "SELECT *
                FROM $table_survey survey 
                INNER JOIN
                $table_survey_invitation survey_invitation
                ON (
                    survey.code = survey_invitation.survey_code AND
                    survey.c_id = survey_invitation.c_id
                )
				WHERE
                    survey_invitation.user = $user_id AND                    
                    survey.avail_from <= '".$now."' AND
                    survey.avail_till >= '".$now."' AND
                    survey.c_id = $course_id AND
                    survey.session_id = $sessionId AND
                    survey_invitation.c_id = $course_id
				";
        $result = Database::query($sql);

        $efv = new ExtraFieldValue('survey');

        while ($row = Database::fetch_array($result, 'ASSOC')) {
            echo '<tr>';
            if ($row['answered'] == 0) {
                echo '<td>';
                echo Display::return_icon(
                    'statistics.png',
                    get_lang('CreateNewSurvey'),
                    array(),
                    ICON_SIZE_TINY
                );
                echo '<a href="'.api_get_path(WEB_CODE_PATH).'survey/fillsurvey.php?course='.$_course['sysCode']
                    .'&invitationcode='.$row['invitation_code'].'&cidReq='.$_course['sysCode'].'">'.$row['title']
                    .'</a></td>';
            } else {
                $isDrhOfCourse = CourseManager::isUserSubscribedInCourseAsDrh(
                    $user_id,
                    $_course
                );
                $icon = Display::return_icon(
                    'statistics_na.png',
                    get_lang('Survey'),
                    array(),
                    ICON_SIZE_TINY
                );
                $showLink = (!api_is_allowed_to_edit(false, true) || $isDrhOfCourse)
                    && $row['visible_results'] != SURVEY_VISIBLE_TUTOR;

                echo '<td>';
                echo $showLink
                    ? Display::url(
                        $icon.PHP_EOL.$row['title'],
                        api_get_path(WEB_CODE_PATH).'survey/reporting.php?'.api_get_cidreq().'&'.http_build_query([
                            'action' => 'questionreport',
                            'survey_id' => $row['survey_id']
                        ])
                    )
                    : $icon.PHP_EOL.$row['title'];
                echo '</td>';
            }
            echo '<td class="text-center">';
            echo ($row['anonymous'] == 1) ? get_lang('Yes') : get_lang('No');
            echo '</td>';
            if ($mandatoryAllowed) {
                $efvMandatory = $efv->get_values_by_handler_and_field_variable(
                    $row['survey_id'],
                    'is_mandatory'
                );
                echo '<td class="text-center">'.($efvMandatory['value'] ? get_lang('Yes') : get_lang('No')).'</td>';
            }

            echo '</tr>';
        }
        echo '</tbody>';
        echo '</table>';
    }

    /**
     * Creates a multi array with the user fields that we can show.
     * We look the visibility with the api_get_setting function
     * The username is always NOT able to change it.
     * @author Julio Montoya Armas <gugli100@gmail.com>, Chamilo: Personality Test modification
     * @return array  array[value_name][name], array[value_name][visibilty]
     */
    public static function make_field_list()
    {
        //	LAST NAME and FIRST NAME
        $field_list_array = array();
        $field_list_array['lastname']['name'] = get_lang('LastName');
        $field_list_array['firstname']['name'] = get_lang('FirstName');

        if (api_get_setting('profile', 'name') != 'true') {
            $field_list_array['firstname']['visibility'] = 0;
            $field_list_array['lastname']['visibility'] = 0;
        } else {
            $field_list_array['firstname']['visibility'] = 1;
            $field_list_array['lastname']['visibility'] = 1;
        }

        $field_list_array['username']['name'] = get_lang('Username');
        $field_list_array['username']['visibility'] = 0;

        //	OFFICIAL CODE
        $field_list_array['official_code']['name'] = get_lang('OfficialCode');

        if (api_get_setting('profile', 'officialcode') != 'true') {
            $field_list_array['official_code']['visibility'] = 1;
        } else {
            $field_list_array['official_code']['visibility'] = 0;
        }

        // EMAIL
        $field_list_array['email']['name'] = get_lang('Email');
        if (api_get_setting('profile', 'email') != 'true') {
            $field_list_array['email']['visibility'] = 1;
        } else {
            $field_list_array['email']['visibility'] = 0;
        }

        // PHONE
        $field_list_array['phone']['name'] = get_lang('Phone');
        if (api_get_setting('profile', 'phone') != 'true') {
            $field_list_array['phone']['visibility'] = 0;
        } else {
            $field_list_array['phone']['visibility'] = 1;
        }
        //	LANGUAGE
        $field_list_array['language']['name'] = get_lang('Language');
        if (api_get_setting('profile', 'language') != 'true') {
            $field_list_array['language']['visibility'] = 0;
        } else {
            $field_list_array['language']['visibility'] = 1;
        }

        // EXTRA FIELDS
        $extra = UserManager::get_extra_fields(0, 50, 5, 'ASC');

        foreach ($extra as $id => $field_details) {
            if ($field_details[6] == 0) {
                continue;
            }
            switch ($field_details[2]) {
                case UserManager::USER_FIELD_TYPE_TEXT:
                    $field_list_array['extra_'.$field_details[1]]['name'] = $field_details[3];
                    if ($field_details[7] == 0) {
                        $field_list_array['extra_'.$field_details[1]]['visibility'] = 0;
                    } else {
                        $field_list_array['extra_'.$field_details[1]]['visibility'] = 1;
                    }
                    break;
                case UserManager::USER_FIELD_TYPE_TEXTAREA:
                    $field_list_array['extra_'.$field_details[1]]['name'] = $field_details[3];
                    if ($field_details[7] == 0) {
                        $field_list_array['extra_'.$field_details[1]]['visibility'] = 0;
                    } else {
                        $field_list_array['extra_'.$field_details[1]]['visibility'] = 1;
                    }
                    break;
                case UserManager::USER_FIELD_TYPE_RADIO:
                    $field_list_array['extra_'.$field_details[1]]['name'] = $field_details[3];
                    if ($field_details[7] == 0) {
                        $field_list_array['extra_'.$field_details[1]]['visibility'] = 0;
                    } else {
                        $field_list_array['extra_'.$field_details[1]]['visibility'] = 1;
                    }
                    break;
                case UserManager::USER_FIELD_TYPE_SELECT:
                    $get_lang_variables = false;
                    if (in_array($field_details[1], array('mail_notify_message', 'mail_notify_invitation', 'mail_notify_group_message'))) {
                        $get_lang_variables = true;
                    }

                    if ($get_lang_variables) {
                        $field_list_array['extra_'.$field_details[1]]['name'] = get_lang($field_details[3]);
                    } else {
                        $field_list_array['extra_'.$field_details[1]]['name'] = $field_details[3];
                    }

                    if ($field_details[7] == 0) {
                        $field_list_array['extra_'.$field_details[1]]['visibility'] = 0;
                    } else {
                        $field_list_array['extra_'.$field_details[1]]['visibility'] = 1;
                    }
                    break;
                case UserManager::USER_FIELD_TYPE_SELECT_MULTIPLE:
                    $field_list_array['extra_'.$field_details[1]]['name'] = $field_details[3];
                    if ($field_details[7] == 0) {
                        $field_list_array['extra_'.$field_details[1]]['visibility'] = 0;
                    } else {
                        $field_list_array['extra_'.$field_details[1]]['visibility'] = 1;
                    }
                    break;
                case UserManager::USER_FIELD_TYPE_DATE:
                    $field_list_array['extra_'.$field_details[1]]['name'] = $field_details[3];
                    if ($field_details[7] == 0) {
                        $field_list_array['extra_'.$field_details[1]]['visibility'] = 0;
                    } else {
                        $field_list_array['extra_'.$field_details[1]]['visibility'] = 1;
                    }
                    break;
                case UserManager::USER_FIELD_TYPE_DATETIME:
                    $field_list_array['extra_'.$field_details[1]]['name'] = $field_details[3];
                    if ($field_details[7] == 0) {
                        $field_list_array['extra_'.$field_details[1]]['visibility'] = 0;
                    } else {
                        $field_list_array['extra_'.$field_details[1]]['visibility'] = 1;
                    }
                    break;
                case UserManager::USER_FIELD_TYPE_DOUBLE_SELECT:
                    $field_list_array['extra_'.$field_details[1]]['name'] = $field_details[3];
                    if ($field_details[7] == 0) {
                        $field_list_array['extra_'.$field_details[1]]['visibility'] = 0;
                    } else {
                        $field_list_array['extra_'.$field_details[1]]['visibility'] = 1;
                    }
                    break;
                case UserManager::USER_FIELD_TYPE_DIVIDER:
                    //$form->addElement('static',$field_details[1], '<br /><strong>'.$field_details[3].'</strong>');
                    break;
            }
        }

        return $field_list_array;
    }

    /**
     * @author Isaac Flores Paz <florespaz@bidsoftperu.com>
     * @param int $user_id User ID
     * @param string $survey_code
     * @param int $user_answer User in survey answer table (user id or anonymous)
     * @return boolean
     */
    public static function show_link_available($user_id, $survey_code, $user_answer)
    {
        $table_survey = Database::get_course_table(TABLE_SURVEY);
        $table_survey_invitation = Database::get_course_table(TABLE_SURVEY_INVITATION);
        $table_survey_answer = Database::get_course_table(TABLE_SURVEY_ANSWER);
        $table_survey_question = Database::get_course_table(TABLE_SURVEY_QUESTION);

        $survey_code = Database::escape_string($survey_code);
        $user_id = intval($user_id);
        $user_answer = Database::escape_string($user_answer);
        $course_id = api_get_course_int_id();

        $sql = 'SELECT COUNT(*) as count
                FROM '.$table_survey_invitation.'
		        WHERE
		            user='.$user_id.' AND
		            survey_code="'.$survey_code.'" AND 
		            answered="1" AND 
		            c_id = '.$course_id;

        $sql2 = 'SELECT COUNT(*) as count 
                 FROM '.$table_survey.' s 
                 INNER JOIN '.$table_survey_question.' q 
                 ON s.survey_id=q.survey_id
				 WHERE 
				    s.code="'.$survey_code.'" AND 
				    q.type NOT IN("pagebreak","comment") AND s.c_id = '.$course_id.' AND q.c_id = '.$course_id.' ';

        $sql3 = 'SELECT COUNT(DISTINCT question_id) as count 
                 FROM '.$table_survey_answer.'
				 WHERE survey_id=(
				    SELECT survey_id FROM '.$table_survey.'
				    WHERE 
				        code = "'.$survey_code.'" AND 
				        c_id = '.$course_id.' 
                    )  AND 
                user="'.$user_answer.'" AND 
                c_id = '.$course_id;

        $result = Database::query($sql);
        $result2 = Database::query($sql2);
        $result3 = Database::query($sql3);

        $row = Database::fetch_array($result, 'ASSOC');
        $row2 = Database::fetch_array($result2, 'ASSOC');
        $row3 = Database::fetch_array($result3, 'ASSOC');

        if ($row['count'] == 1 && $row3['count'] != $row2['count']) {

            return true;
        } else {
            return false;
        }
    }

    /**
     * Display survey question chart
     * @param array $chartData
     * @param boolean $hasSerie Tells if the chart has a serie. False by default
     * @param string $chartContainerId
     * @return string (direct output)
     */
    public static function drawChart(
        $chartData,
        $hasSerie = false,
        $chartContainerId = 'chartContainer'
    ) {
        $htmlChart = '';
        if (api_browser_support("svg")) {
            $htmlChart .= api_get_js("d3/d3.v3.5.4.min.js");
            $htmlChart .= api_get_js("dimple.v2.1.2.min.js").'
            <script>
            var svg = dimple.newSvg("#'.$chartContainerId.'", "100%", 400);
            var data = [';
            $serie = array();
            $order = array();
            foreach ($chartData as $chartDataElement) {
                $htmlChart .= '{"';
                if (!$hasSerie) {
                    $htmlChart .= get_lang("Option").'":"'.$chartDataElement['option'].'", "';
                    array_push($order, $chartDataElement['option']);
                } else {
                    if (!is_array($chartDataElement['serie'])) {
                        $htmlChart .= get_lang("Option").'":"'.$chartDataElement['serie'].'", "'.
                            get_lang("Score").'":"'.$chartDataElement['option'].'", "';
                        array_push($serie, $chartDataElement['serie']);
                    } else {
                        $htmlChart .= get_lang("Serie").'":"'.$chartDataElement['serie'][0].'", "'.
                            get_lang("Option").'":"'.$chartDataElement['serie'][1].'", "'.
                            get_lang("Score").'":"'.$chartDataElement['option'].'", "';
                    }
                }
                $htmlChart .= get_lang("Votes").'":"'.$chartDataElement['votes'].
                    '"},';
            }
            rtrim($htmlChart, ",");
            $htmlChart .= '];
                var myChart = new dimple.chart(svg, data);
                myChart.addMeasureAxis("y", "'.get_lang("Votes").'");';
            if (!$hasSerie) {
                $htmlChart .= 'var xAxisCategory = myChart.addCategoryAxis("x", "'.get_lang("Option").'");
                    xAxisCategory.addOrderRule('.json_encode($order).');
                    myChart.addSeries("'.get_lang("Option").'", dimple.plot.bar);';
            } else {
                if (!is_array($chartDataElement['serie'])) {
                    $serie = array_values(array_unique($serie));
                    $htmlChart .= 'var xAxisCategory = myChart.addCategoryAxis("x", ["'.get_lang("Option").'","'
                        .get_lang("Score").'"]);
                        xAxisCategory.addOrderRule('.json_encode($serie).');
                        xAxisCategory.addGroupOrderRule("'.get_lang("Score").'");
                        myChart.addSeries("'.get_lang("Option").'", dimple.plot.bar);';
                } else {
                    $htmlChart .= 'myChart.addCategoryAxis("x", ["'.get_lang("Option").'","'.get_lang("Score").'"]);
                        myChart.addSeries("'.get_lang("Serie").'", dimple.plot.bar);';
                }
            }
            $htmlChart .= 'myChart.draw();
                </script>';
        }

        return $htmlChart;
    }

    /**
     * Set a flag to the current survey as answered by the current user
     * @param string $surveyCode The survey code
     * @param int $courseId The course ID
     */
    public static function flagSurveyAsAnswered($surveyCode, $courseId)
    {
        $currentUserId = api_get_user_id();
        $flag = sprintf("%s-%s-%d", $courseId, $surveyCode, $currentUserId);

        if (!isset($_SESSION['filled_surveys'])) {
            $_SESSION['filled_surveys'] = array();
        }

        $_SESSION['filled_surveys'][] = $flag;
    }

    /**
     * Check whether a survey was answered by the current user
     * @param string $surveyCode The survey code
     * @param int $courseId The course ID
     * @return boolean
     */
    public static function isSurveyAnsweredFlagged($surveyCode, $courseId)
    {
        $currentUserId = api_get_user_id();
        $flagToCheck = sprintf("%s-%s-%d", $courseId, $surveyCode, $currentUserId);

        if (!isset($_SESSION['filled_surveys'])) {
            return false;
        }

        if (!is_array($_SESSION['filled_surveys'])) {
            return false;
        }

        foreach ($_SESSION['filled_surveys'] as $flag) {
            if ($flagToCheck != $flag) {
                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * Check if the current survey has answers
     *
     * @param int $surveyId
     * @return boolean return true if the survey has answers, false otherwise
     */
    public static function checkIfSurveyHasAnswers($surveyId)
    {
        $tableSurveyAnswer = Database::get_course_table(TABLE_SURVEY_ANSWER);
        $courseId = api_get_course_int_id();
        $surveyId = (int) $surveyId;

        if (empty($courseId) || empty($surveyId)) {
            return false;
        }

        $sql = "SELECT * FROM $tableSurveyAnswer
                WHERE
                    c_id = $courseId AND
                    survey_id = '".$surveyId."'
                ORDER BY answer_id, user ASC";
        $result = Database::query($sql);
        $response = Database::affected_rows($result);

        return $response > 0;
    }
}
