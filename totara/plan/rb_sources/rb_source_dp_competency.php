<?php
/*
 * This file is part of Totara LMS
 *
 * Copyright (C) 2010 onwards Totara Learning Solutions LTD
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 * @author Simon Coggins <simon.coggins@totaralms.com>
 * @package totara
 * @subpackage reportbuilder
 */

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/totara/plan/lib.php');

/**
 * A report builder source for DP competencies
 */
class rb_source_dp_competency extends rb_base_source {

    public $base, $joinlist, $columnoptions, $filteroptions;
    public $contentoptions, $paramoptions, $defaultcolumns;
    public $defaultfilters, $requiredcolumns, $sourcetitle;
    public $dp_plans;


    /**
     * Constructor
     */
    public function __construct() {
        global $DB;

        $this->base = "(select distinct ".
                $DB->sql_concat_join(
                        "','",
                        array(
                            sql_cast2char('cr.userid'),
                            sql_cast2char('cr.competencyid')
                        )
                ) . " as id, ".
                "cr.userid as userid, cr.competencyid as competencyid ".
                "from {comp_record} cr ".
                // exclude placeholder evidence entries (generated by competency cron)
                "where cr.proficiency IS NOT NULL " .
                " UNION ".
                "select distinct ".
                $DB->sql_concat_join(
                        "','",
                        array(
                            sql_cast2char('p1.userid'),
                            sql_cast2char('pca1.competencyid')
                        )
                )." as id, ".
                "p1.userid as userid, pca1.competencyid as competencyid ".
                "from {dp_plan_competency_assign} pca1 ".
                "inner join {dp_plan} p1 ".
                "on pca1.planid = p1.id)";

        $this->joinlist = $this->define_joinlist();
        $this->columnoptions = $this->define_columnoptions();
        $this->filteroptions = $this->define_filteroptions();
        $this->contentoptions = $this->define_contentoptions();
        $this->paramoptions = $this->define_paramoptions();
        $this->defaultcolumns = $this->define_defaultcolumns();
        $this->defaultfilters = array();
        $this->requiredcolumns = array();
        $this->dp_plans = array();
        $this->sourcetitle = get_string('sourcetitle', 'rb_source_dp_competency');
        parent::__construct();
    }

    //
    //
    // Methods for defining contents of source
    //
    //

    /**
     * Creates the array of rb_join objects required for this->joinlist
     *
     * @global object $CFG
     * @return array
     */
    protected function define_joinlist() {
        global $CFG;

        $joinlist = array();

        // to get access to position type constants
        require_once($CFG->dirroot . '/totara/reportbuilder/classes/rb_join.php');

        /**
         * dp_plan has userid, dp_plan_competency_assign has competencyid. In order to
         * avoid multiplicity we need to join them together before we join
         * against the rest of the query
         */
        $joinlist[] = new rb_join(
                'dp_competency',
                'LEFT',
                "(select
  p.id as planid,
  p.templateid as templateid,
  p.userid as userid,
  p.name as planname,
  p.description as plandescription,
  p.startdate as planstartdate,
  p.enddate as planenddate,
  p.status as planstatus,
  pc.id as id,
  pc.competencyid as competencyid,
  pc.priority as priority,
  pc.duedate as duedate,
  pc.approved as approved,
  pc.scalevalueid as scalevalueid
from
  {dp_plan} p
  inner join {dp_plan_competency_assign} pc
  on p.id = pc.planid)",
                'dp_competency.userid = base.userid and dp_competency.competencyid = base.competencyid',
                REPORT_BUILDER_RELATION_ONE_TO_MANY
        );

        $joinlist[] = new rb_join(
                'template',
                'LEFT',
                '{dp_template}',
                'dp_competency.templateid = template.id',
                REPORT_BUILDER_RELATION_MANY_TO_ONE,
                'dp_competency'
        );

        $joinlist[] = new rb_join(
                'competency',
                'LEFT',
                '{comp}',
                'base.competencyid = competency.id',
                REPORT_BUILDER_RELATION_MANY_TO_ONE
        );

        $joinlist[] = new rb_join(
                'priority',
                'LEFT',
                '{dp_priority_scale_value}',
                'dp_competency.priority = priority.id',
                REPORT_BUILDER_RELATION_MANY_TO_ONE,
                'dp_competency'
        );

        $joinlist[] = new rb_join(
                'scale_value',
                'LEFT',
                '{comp_scale_values}',
                'dp_competency.scalevalueid = scale_value.id',
                REPORT_BUILDER_RELATION_MANY_TO_ONE,
                'dp_competency'
        );

        $joinlist[] = new rb_join(
                'linkedcourses',
                'LEFT',
                "(SELECT itemid1 AS compassignid,
                    count(id) AS count
                    FROM {dp_plan_component_relation}
                    WHERE component1 = 'competency' AND component2 = 'course'
                    GROUP BY itemid1)",
                'dp_competency.id = linkedcourses.compassignid',
                REPORT_BUILDER_RELATION_MANY_TO_ONE,
                'dp_competency'
        );

        $joinlist[] = new rb_join(
                'comp_record',
                'LEFT',
                '{comp_record}',
                '(base.competencyid = comp_record.competencyid
                  AND comp_record.userid = base.userid)',
                  REPORT_BUILDER_RELATION_ONE_TO_ONE
        );

        $joinlist[] = new rb_join(
                'evidence_scale_value',
                'LEFT',
                '{comp_scale_values}',
                'comp_record.proficiency = evidence_scale_value.id',
                REPORT_BUILDER_RELATION_MANY_TO_ONE,
                'comp_record'
        );

        $joinlist[] = new rb_join(
                'comp_type',
                'LEFT',
                '{comp_type}',
                'competency.typeid = comp_type.id',
                REPORT_BUILDER_RELATION_ONE_TO_ONE,
                'competency'
        );

        $this->add_user_table_to_joinlist($joinlist, 'base','userid');
        $this->add_position_tables_to_joinlist($joinlist, 'base', 'userid');
        $this->add_manager_tables_to_joinlist($joinlist, 'position_assignment', 'reportstoid');
        $this->add_cohort_user_tables_to_joinlist($joinlist, 'base', 'userid');

        return $joinlist;
    }

    /**
     * Creates the array of rb_column_option objects required for
     * $this->columnoptions
     *
     * @return array
     */
    protected function define_columnoptions() {
        $columnoptions = array();

        $columnoptions[] = new rb_column_option(
                'plan',
                'name',
                get_string('planname', 'rb_source_dp_competency'),
                'dp_competency.planname',
                array(
                    'defaultheading' => get_string('plan', 'rb_source_dp_competency'),
                    'joins' => 'dp_competency',
                    'dbdatatype' => 'char',
                    'outputformat' => 'text'
                )
        );
        $columnoptions[] = new rb_column_option(
                'plan',
                'planlink',
                get_string('plannamelinked', 'rb_source_dp_competency'),
                'dp_competency.planname',
                array(
                    'defaultheading' => get_string('plan', 'rb_source_dp_competency'),
                    'joins' => 'dp_competency',
                    'displayfunc' => 'planlink',
                    'extrafields' => array( 'plan_id' => 'dp_competency.planid' )
                )
        );
        $columnoptions[] = new rb_column_option(
                'plan',
                'startdate',
                get_string('planstartdate', 'rb_source_dp_competency'),
                'dp_competency.planstartdate',
                array(
                    'joins' => 'dp_competency',
                    'displayfunc' => 'nice_date'
                )
        );
        $columnoptions[] = new rb_column_option(
                'plan',
                'enddate',
                get_string('planenddate', 'rb_source_dp_competency'),
                'dp_competency.planenddate',
                array(
                    'joins' => 'dp_competency',
                    'displayfunc' => 'nice_date'
                )
        );
        $columnoptions[] = new rb_column_option(
                'plan',
                'status',
                get_string('planstatus', 'rb_source_dp_competency'),
                'dp_competency.planstatus',
                array(
                    'joins' => 'dp_competency',
                    'displayfunc' => 'plan_status'
                )
        );

        $columnoptions[] = new rb_column_option(
                'template',
                'name',
                get_string('templatename', 'rb_source_dp_competency'),
                'template.shortname',
                array(
                    'defaultheading' => get_string('plantemplate', 'rb_source_dp_competency'),
                    'joins' => 'template',
                    'dbdatatype' => 'char',
                    'outputformat' => 'text'
                )
        );
        $columnoptions[] = new rb_column_option(
                'template',
                'startdate',
                get_string('templatestartdate', 'rb_source_dp_competency'),
                'template.startdate',
                array(
                    'joins' => 'template',
                    'displayfunc' => 'nice_date'
                )
        );
        $columnoptions[] = new rb_column_option(
                'template',
                'enddate',
                get_string('templateenddate', 'rb_source_dp_competency'),
                'template.enddate',
                array(
                    'joins' => 'template',
                    'displayfunc' => 'nice_date'
                )
        );

        $columnoptions[] = new rb_column_option(
                'competency',
                'fullname',
                get_string('competencyname', 'rb_source_dp_competency'),
                'competency.fullname',
                array(
                    'defaultheading' => get_string('competencyname', 'rb_source_dp_competency'),
                    'joins' => 'competency',
                    'dbdatatype' => 'char',
                    'outputformat' => 'text'
                )
        );

        $columnoptions[] = new rb_column_option(
                'competency',
                'duedate',
                get_string('competencyduedate', 'rb_source_dp_competency'),
                'dp_competency.duedate',
                array(
                    'displayfunc' => 'nice_date',
                    'joins' => 'dp_competency'
                )
        );

        $columnoptions[] = new rb_column_option(
                'competency',
                'priority',
                get_string('competencypriority', 'rb_source_dp_competency'),
                'priority.name',
                array(
                    'joins' => 'priority',
                    'dbdatatype' => 'char',
                    'outputformat' => 'text'
                )
        );

        $columnoptions[] = new rb_column_option(
                'competency',
                'status',
                get_string('competencystatus', 'rb_source_dp_competency'),
                'dp_competency.approved',
                array(
                    'displayfunc' => 'plan_item_status',
                    'joins' => 'dp_competency'
                )
        );

        $columnoptions[] = new rb_column_option(
                'competency',
                'competencyeditstatus',
                get_string('competencyeditstatus', 'rb_source_dp_competency'),
                'dp_competency.competencyid',
                array(
                    'defaultheading' => 'Plan',
                    'joins' => 'dp_competency',
                    'displayfunc' => 'competencyeditstatus',
                    'extrafields' => array( 'planid' => 'dp_competency.planid' )
                )
        );

        $columnoptions[] = new rb_column_option(
                'competency',
                'type',
                get_string('competencytype', 'rb_source_dp_competency'),
                'comp_type.fullname',
                array(
                    'joins' => 'comp_type',
                    'dbdatatype' => 'char',
                    'outputformat' => 'text'
                )
            );

        $columnoptions[] = new rb_column_option(
                'competency',
                'type_id',
                get_string('competencytypeid', 'rb_source_dp_competency'),
                'competency.typeid',
                array(
                    'joins' => 'competency'
                )
        );


        $columnoptions[] = new rb_column_option(
                'competency',
                'proficiency',
                get_string('competencyproficiency', 'rb_source_dp_competency'),
                // source of proficiency depends on plan status
                // take 'live' value for active plans and static
                // stored value for completed plans
                'CASE WHEN dp_competency.planstatus = ' . DP_PLAN_STATUS_COMPLETE . '
                THEN
                    scale_value.name
                ELSE
                    evidence_scale_value.name
                END',
                array(
                    'joins' => array('dp_competency', 'scale_value', 'evidence_scale_value'),
                    'dbdatatype' => 'char',
                    'outputformat' => 'text'
                )
        );

        // returns 1 for 'proficient' competencies, 0 otherwise
        $columnoptions[] = new rb_column_option(
                'competency',
                'proficient',
                get_string('competencyproficient', 'rb_source_dp_competency'),
                // source of proficient status depends on plan status
                // take 'live' value for active plans and static
                // stored value for completed plans
                'CASE WHEN dp_competency.planstatus = ' . DP_PLAN_STATUS_COMPLETE . '
                THEN
                    scale_value.proficient
                ELSE
                    evidence_scale_value.proficient
                END',
                array(
                    'joins' => array('dp_competency', 'scale_value', 'evidence_scale_value'),
                    'displayfunc' => 'yes_or_no'
                )
        );

        $columnoptions[] = new rb_column_option(
                'competency',
                'proficiencyandapproval',
                get_string('competencyproficiencyandapproval', 'rb_source_dp_competency'),
                // source of proficiency depends on plan status
                // take 'live' value for active plans and static
                // stored value for completed plans
                'CASE WHEN dp_competency.planstatus = ' . DP_PLAN_STATUS_COMPLETE . '
                THEN
                    scale_value.name
                ELSE
                    evidence_scale_value.name
                END',
                array(
                    'joins' => array('dp_competency', 'scale_value', 'evidence_scale_value', 'competency'),
                    'displayfunc' => 'proficiency_and_approval_menu',
                    'defaultheading' => get_string('competencyproficiency', 'rb_source_dp_competency'),
                    'extrafields' => array(
                        'approved' => 'dp_competency.approved',
                        'compscaleid' => 'scale_value.scaleid',
                        'compscalevalueid' => 'scale_value.id',
                        'compevscaleid' => 'evidence_scale_value.scaleid',
                        'compevscalevalueid' => 'evidence_scale_value.id',
                        'compframeworkid' => 'competency.frameworkid',
                        'plancompid' => 'dp_competency.id',
                        'planid' => 'dp_competency.planid',
                        'competencyid' => 'dp_competency.competencyid',
                        'userid' => 'dp_competency.userid'
                    )
                )
        );

        $columnoptions[] = new rb_column_option(
                'competency',
                'linkedcourses',
                get_string('courses', 'rb_source_dp_competency'),
                'linkedcourses.count',
                array(
                    'joins' => 'linkedcourses'
                )
        );

        $columnoptions[] = new rb_column_option(
                'competency',
                'statushistorylink',
                get_string('statushistorylinkcolumn', 'rb_source_dp_competency'),
                'base.userid',
                array('defaultheading' => get_string('statushistorylinkheading', 'rb_source_dp_competency'),
                      'displayfunc' => 'status_history_link',
                      'extrafields' => array('competencyid' => 'base.competencyid'),
                      'noexport' => true,
                      'nosort' => true)
        );

        $this->add_user_fields_to_columns($columnoptions);
        $this->add_position_fields_to_columns($columnoptions);
        $this->add_manager_fields_to_columns($columnoptions);
        $this->add_cohort_user_fields_to_columns($columnoptions);

        return $columnoptions;
    }

    /**
     * Creates the array of rb_filter_option objects required for $this->filteroptions
     * @return array
     */
    protected function define_filteroptions() {
        $filteroptions = array();

        $filteroptions[] = new rb_filter_option(
                'competency',
                'fullname',
                get_string('competencyname', 'rb_source_dp_competency'),
                'text'
        );

        $filteroptions[] = new rb_filter_option(
                'competency',
                'priority',
                get_string('competencypriority', 'rb_source_dp_competency'),
                'text'
        );

        $filteroptions[] = new rb_filter_option(
                'competency',
                'duedate',
                get_string('competencyduedate', 'rb_source_dp_competency'),
                'date'
        );

        $filteroptions[] = new rb_filter_option(
                'plan',
                'name',
                get_string('planname', 'rb_source_dp_competency'),
                'text'
        );

        $filteroptions[] = new rb_filter_option(
                'competency',
                'type_id',
                get_string('competencytype', 'rb_source_dp_competency'),
                'select',
                array(
                    'selectfunc' => 'competency_type_list',
                    'attributes' => rb_filter_option::select_width_limiter(),
                )
        );

        $this->add_user_fields_to_filters($filteroptions);
        $this->add_position_fields_to_filters($filteroptions);
        $this->add_manager_fields_to_filters($filteroptions);
        $this->add_cohort_user_fields_to_filters($filteroptions);

        return $filteroptions;
    }

    /**
     * Creates the array of rb_content_option object required for $this->contentoptions
     * @return array
     */
    protected function define_contentoptions() {
        $contentoptions = array(
            new rb_content_option(
                'current_pos',
                get_string('currentpos', 'totara_reportbuilder'),
                'position.path',
                'position'
            ),
            new rb_content_option(
                'current_org',
                get_string('currentorg', 'totara_reportbuilder'),
                'organisation.path',
                'organisation'
            )
        );

        // Include the rb_user_content content options for this report
        $contentoptions[] = new rb_content_option(
            'user',
            get_string('users'),
            array(
                'userid' => 'base.userid',
                'managerid' => 'position_assignment.managerid',
                'managerpath' => 'position_assignment.managerpath',
                'postype' => 'position_assignment.type',
            ),
            'position_assignment'
        );
        return $contentoptions;
    }

    protected function define_paramoptions() {
        global $CFG;
        require_once($CFG->dirroot.'/totara/plan/lib.php');
        $paramoptions = array();
        $paramoptions[] = new rb_param_option(
                'userid',
                'base.userid'
        );
        $paramoptions[] = new rb_param_option(
                'rolstatus',
                'CASE WHEN dp_competency.planstatus = ' . DP_PLAN_STATUS_COMPLETE . '
                THEN
                    CASE WHEN scale_value.proficient = 1
                    THEN \'completed\' ELSE \'active\'
                    END
                ELSE
                    CASE WHEN evidence_scale_value.proficient = 1
                    THEN \'completed\' ELSE \'active\'
                    END
                END',
                array('dp_competency', 'scale_value', 'evidence_scale_value'),
                'string'
        );
        return $paramoptions;
    }

    protected function define_defaultcolumns() {
        $defaultcolumns = array(
            array(
                'type' => 'plan',
                'value' => 'planlink',
            ),
            array(
                'type' => 'plan',
                'value' => 'status',
            ),
            array(
                'type' => 'competency',
                'value' => 'fullname',
            ),
            array(
                'type' => 'competency',
                'value' => 'priority',
            ),
            array(
                'type' => 'competency',
                'value' => 'duedate',
            ),
            array(
                'type' => 'competency',
                'value' => 'proficiencyandapproval',
            ),
        );
        return $defaultcolumns;
    }

    public function rb_display_status_history_link($userid, $row, $isexport = false) {
        if ($isexport) {
            return '';
        }

        if ($userid == 0) {
            return '';
        }

        $url = new moodle_url('/totara/hierarchy/prefix/competency/statushistoryreport.php',
                array('userid' => $userid, 'competencyid' => $row->competencyid));

        return html_writer::link($url, get_string('statushistorylinkheading', 'rb_source_dp_competency'));
    }

    function rb_display_proficiency_and_approval($status, $row) {
        global $CFG;
        // needed for approval constants
        require_once($CFG->dirroot . '/totara/plan/lib.php');

        $content = array();
        $approved = isset($row->approved) ? $row->approved : null;

        if ($status) {
            $content[] = $status;
        }

        // highlight if the item has not yet been approved
        if ($approved != DP_APPROVAL_APPROVED) {
            $itemstatus = $this->rb_display_plan_item_status($approved);
            if ($itemstatus) {
                $content[] = $itemstatus;
            }
        }
        return implode(html_writer::empty_tag('br'), $content);
    }


    /**
     * Displays an icon linked to the "add competency evidence" page for this competency
     * @param $competencyid
     * @param $row
     */
    public function rb_display_competencyeditstatus($competencyid, $row) {

        $planid = isset($row->planid) ? $row->planid : null;
        if ($planid) {

            // Store the plan object so that we don't have to generate one for each row
            // of the report
            if (array_key_exists($planid, $this->dp_plans)) {
                $plan = $this->dp_plans[$planid];
            } else {
                $plan = new development_plan($planid);
                $this->dp_plans[$planid] = $plan;
            }

            $competencycomponent = $plan->get_component('competency');

            $row->competencyid = $competencyid;
            return $competencycomponent->display_comp_add_evidence_icon($row, qualified_me());
        }
    }


    /**
     * A hash of competency scales. The key is the framework id, and the value
     * is an array as returned by get_records_menu() of the competency scale
     * for that framework
     * @var array
     */
    private $compscales = array();

    /**
     * Displays the competency's proficiency/approval status, and if the current user would have permission
     * to change the competency's status via the competency page of the learning plan, it gives them
     * a drop-down menu to change the status, which saves changes via Javascript
     * @param unknown_type $status
     * @param unknown_type $row
     */
    public function rb_display_proficiency_and_approval_menu($status, $row) {
        global $CFG, $DB;
        // needed for approval constants
        require_once($CFG->dirroot . '/totara/plan/lib.php');
        require_once($CFG->dirroot."/totara/core/js/lib/setup.php");

        $content = array();
        $approved = isset($row->approved) ? $row->approved : null;
        $compframeworkid = isset($row->compframeworkid) ? $row->compframeworkid : null;
        $planid = isset($row->planid) ? $row->planid : null;
        $compevscalevalueid = isset($row->compevscalevalueid) ? $row->compevscalevalueid : null;
        $plancompid = isset($row->plancompid) ? $row->plancompid : null;
        $competencyid = isset($row->competencyid) ? $row->competencyid : null;

        if (!$planid) {
            return '';
        } else {
            if (array_key_exists($planid, $this->dp_plans)) {
                $plan = $this->dp_plans[$planid];
            } else {
                $plan = new development_plan($planid);
                $this->dp_plans[$planid] = $plan;
            }

            $competencycomponent = $plan->get_component('competency');
            if ($competencycomponent->can_update_competency_evidence($row)) {

                // Get the info we need about the framework
                if (array_key_exists( $compframeworkid, $this->compscales)) {
                    $compscale = $this->compscales[$compframeworkid];
                } else {
                    $sql = "SELECT
                                cs.defaultid as defaultid, cs.id as scaleid
                            FROM {comp} c
                            JOIN {comp_scale_assignments} csa
                                ON c.frameworkid = csa.frameworkid
                            JOIN {comp_scale} cs
                                ON csa.scaleid = cs.id
                            WHERE c.id= ?";
                    $scaledetails = $DB->get_record_sql($sql, array($competencyid));
                    $formatscale = $DB->get_records_menu('comp_scale_values', array('scaleid' => $scaledetails->scaleid), 'sortorder');

                    $compscale = array();
                    foreach ($formatscale as $key => $scale) {
                        $compscale[$key] = format_string($scale);
                    }
                    $this->compscales[$compframeworkid] = $compscale;
                }

                local_js();

                $action = "var response; ".
                          "response = \$.get(".
                              "'{$CFG->wwwroot}/totara/plan/components/competency/update-competency-setting.php".
                              "?competencyid={$competencyid}".
                              "&planid={$planid}".
                              "&prof=' + $(this).val()".
                              "); ";
                $attributes = array('onchange' => $action);
                $content[] = html_writer::select($compscale,
                                              'competencyevidencestatus'.$plancompid,
                                              $compevscalevalueid,
                                              array(($compevscalevalueid ? '' : 0) => ($compevscalevalueid ? '' : get_string('notset', 'totara_hierarchy'))),
                                              $attributes);
            } else if ($status) {
                $content[] = $status;
            }
        }

        // highlight if the item has not yet been approved
        if ($approved != DP_APPROVAL_APPROVED) {
            $itemstatus = $this->rb_display_plan_item_status($approved);
            if ($itemstatus) {
                $content[] = $itemstatus;
            }
        }
        return implode(html_writer::empty_tag('br'), $content);
    }
}