<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 *
 * @package     report_hucs
 * @copyright   2024 Takahiro Sumiya
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// report/hucs
// テンプレートのコースに置いている「コメントシート」はフィードバックを使っているが、
// 評定表に入らないので、提出状況を一覧できず不便
//
// このプラグインは、reportプラグインとして、フィードバックの一覧表を表示するもの
// 縦にコース登録学生、横に配置されたコメントシートを並べ、提出した日付を表示する。
// 表のダウンロード時には提出した日付時刻を表示する。
//
// コメントシートかどうかは、タイトルに "Comment Sheet" の文字列が含まれているかどうかで判別。
// テンプレートコースのコメントシートのタイトルは
//    <span lang="ja" class="multilang">コメントシート</span><span lang="en" class="multilang">Comment sheet</span> 1
// となっている。
//
// 表はページングをしているが1ページ50件に固定

////////////////////////////////////////////////////////////////////i
// コースにあるフィードバックを全て取得
// とりあえず名前に　”Comment Sheet" が含まれているもののみを残して表の対象に
// 戻り値はfeedbackの内部idをキーにもつハッシュになる
// 全てのカラムをとってきているため、 $feedback[id]->??? で???に全てのカラムが入る
function get_feedback_id ( $courseid ) {
	global $DB;
	$feedback = $DB->get_records('feedback',array('course'=>$courseid),"id ASC" );
	foreach ( array_keys($feedback) as $k ) {
		if(strpos($feedback[$k]->name,"Comment sheet")===false){
			unset($feedback[$k]);
		}
	}
	return($feedback);
}

////////////////////////////////////////////////////////////////////i
// feedback のIDを指定して提出状況を取得
// 複数回の提出があることを考慮して、一番新しいものを取得するようにする
function get_feedback_submission_status ( $feedbackid ) {
	global $DB;
	$submission = $DB->get_records_sql(
	'select userid,max(timemodified) submissiondate from {feedback_completed} where feedback=:fid group by userid',
	[
	'fid'  => $feedbackid
	]
	);

	return($submission);
}

use core\report_helper;
require_once(dirname(__FILE__) . '/../../config.php');
require_once($CFG->libdir.'/tablelib.php');

$courseid = required_param('id', PARAM_INT);
$page = optional_param('page',0,PARAM_INT);
$downloadtype = optional_param('download','',PARAM_ALPHA);

$params['id'] = $courseid;
$course = $DB->get_record('course',array('id'=>$courseid));
require_course_login($course);

$coursecontext = context_course::instance($course->id);
require_capability('report/hucs:view', $coursecontext);

$students = get_enrolled_users($coursecontext, 'mod/feedback:complete');

$PAGE->set_pagelayout('report');
$PAGE->set_url($CFG->wwwroot.'/report/hucs/index.php',$params);
$PAGE->set_heading($course->fullname);

$pluginname = get_string('pluginname', 'report_hucs');

// 表の項目を設定する
// 学生情報を固定で出力
// あとはfeedbackの数に応じて
// 最後に提出回数を表示する

$headers = array('idnumber','fullname','email');
$hnames = array(get_string('hucs:lab_idnumber', 'report_hucs'),get_string('hucs:lab_fullname', 'report_hucs'),get_string('hucs:lab_email', 'report_hucs'));
$fids = get_feedback_id($courseid);
$fstats = array();
$anon_f = array();
$i=1;
foreach( array_keys($fids) as $fid ) {
	$k = 'cs'.$i;
	$fstats[$k] = get_feedback_submission_status($fid);
	$headers[]=$k;
	$cm = $DB->get_record_sql(
	"select id from {course_modules} where course=:cid and instance=:fid and module=(select id from {modules} where name='feedback')",
	[
	'cid'  => $courseid,
	'fid'  => $fid
	]
	);
	$murl = new moodle_url('/mod/feedback/show_entries.php', array('id' => $cm->id));
//	$courseurl = $url->out();
//	$title=html_entity_decode($fids[$fid]->name);
	$title=strip_tags($fids[$fid]->name);
	$hnames[]=sprintf("<a href=\"%s\" title=\"%s\">%s</a>",$murl->out(),$title,$k);
	$anon_f[$k]=$fids[$fid]->anonymous; // 1=anonymous 2=not anonymous
	$i += 1;
}
$headers[]="count";
$hnames[]=get_string('hucs:lab_count', 'report_hucs');

// ----------------------------------------------------
$table = new flexible_table('cs_table-' . $courseid);
$table->define_columns($headers);
$table->define_headers($hnames);
$table->define_baseurl($PAGE->url);
//$table->sortable(true, 'email', SORT_ASC);
//$table->sortable(true, 'email', SORT_ASC);
$table->is_downloadable(true);
$table->show_download_buttons_at([TABLE_P_BOTTOM]);
$table->pagesize(50,count($students));
if ( $downloadtype ) {
	$table->is_downloading($downloadtype,"comment_sheet_submission","");
}
if ( $table->is_downloading() ) {
	$perpage = 50000;
} else {
	$perpage = 50;
}
$table->setup();

usort($students, function($a, $b) {
	return strcmp($a->email, $b->email);
});


// 学生の一覧 ($students) をもとに
// 表を埋めていく

if( !$table->is_downloading() ) {
	echo $OUTPUT->header();
	report_helper::print_report_selector($pluginname);
}

$page = $page*$perpage;
foreach ( $students as $s ) { // 学生ごと
	if ( $page>0 ) {
		$page -= 1;
		continue;
	}
	$row = array();
	$row[] = $s->idnumber;
	$row[] = fullname($s);
	$row[] = $s->email;
	$i=0;
	$count=0;
	foreach($headers as $k) { // 学生ごと x 課題ごと
		$i += 1;
		if($i<=2) {
			continue;
		}
		if ( array_key_exists($k, $fstats) ) { // 課題に提出物が少なくとも一つある
			if ( array_key_exists($s->id,$fstats[$k] )) { // 課題 x 学生 で提出あり
				// セルの値。ダウンロード中か、課題は匿名提出と設定されているか、で異なる
				if ( $table->is_downloading() ) {
					if($anon_f[$k]==1) {
						$row[] = "done";
					} else {
						$row[] = date( 'Y/m/d H:i:s', $fstats[$k][$s->id]->submissiondate );
//						$row[] = userdate( $fstats[$k][$s->id]->submissiondate,'%Y/%m/%d %H:%M:%S' );
					}
				} else {
					if($anon_f[$k]==1) {
						$row[] = "&#10003;"; // チェックマーク
					} else {
						$row[] = date( 'm/d', $fstats[$k][$s->id]->submissiondate );
//						$row[] = userdate( $fstats[$k][$s->id]->submissiondate,'%m/%d' );
					}
				}
				$count +=1;
			} else {
				$row[]= '';
			}
		}
	}
	$row[]=$count;
	$table->add_data($row);
	$perpage -= 1;
	if ( $perpage==0 ) {
		break;
	}
}


//$table->set_sql('id,anonymous,name', '{feedback}', '1=1');
//$table->out(20, true); // 表示する行数とページングの有無

$table->finish_output();
if( !$table->is_downloading() ) {
	echo $OUTPUT->footer();
}
