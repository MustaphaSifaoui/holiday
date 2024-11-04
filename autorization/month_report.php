<?php
/* Copyright (C) 2007-2010  Laurent Destailleur     <eldy@users.sourceforge.net>
 * Copyright (C) 2011       François Legastelois    <flegastelois@teclib.com>
 * Copyright (C) 2018-2019  Frédéric France         <frederic.france@netlogic.fr>
 * Copyright (C) 2020       Tobias Sekan            <tobias.sekan@startmail.com>
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
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 *      \file       month_report.php
 *      \ingroup    holiday
 *      \brief      Monthly report of leave requests.
 */


// Load Dolibarr environment
require '../../main.inc.php';
require_once DOL_DOCUMENT_ROOT . '../holiday/class/holiday.class.php';
require_once DOL_DOCUMENT_ROOT . '../user/class/user.class.php';
require_once DOL_DOCUMENT_ROOT . '../core/lib/files.lib.php';
require_once DOL_DOCUMENT_ROOT . '../core/lib/date.lib.php';
require_once DOL_DOCUMENT_ROOT . '../core/class/html.formother.class.php';

// Load translation files required by the page
$langs->loadLangs(array('holiday', 'hrm'));

// Security check
$socid = 0;
$id = GETPOST('id', 'int');

if ($user->socid > 0) { // Protection if external user
    //$socid = $user->socid;
    accessforbidden();
}
$result = restrictedArea($user, 'holiday', $id, '');

$action = GETPOST('action', 'aZ09') ? GETPOST('action', 'aZ09') : 'view';
$massaction = GETPOST('massaction', 'alpha');
$contextpage = GETPOST('contextpage', 'aZ');
$optioncss = GETPOST('optioncss', 'aZ');

$search_ref = GETPOST('search_ref', 'alphanohtml');
$search_employee = GETPOST('search_employee', 'int');
$search_description = GETPOST('search_description', 'alphanohtml');
$search_hdat = GETPOST('search_hdat', 'alphanohtml'); // Added search_hdat

$limit = GETPOST('limit', 'int') ? GETPOST('limit', 'int') : $conf->liste_limit;
$sortfield = GETPOST('sortfield', 'aZ09comma');
$sortorder = GETPOST('sortorder', 'aZ09comma');

if (!$sortfield) {
    $sortfield = "cp.rowid";
}
if (!$sortorder) {
    $sortorder = "ASC";
}

$page = GETPOSTISSET('pageplusone') ? (GETPOST('pageplusone') - 1) : GETPOST("page", 'int');
if (empty($page) || $page == -1) {
    $page = 0;
}

$hookmanager->initHooks(array('leavemovementlist'));

$arrayfields = array();
$arrayofmassactions = array();

/*
 * Actions
 */

if (GETPOST('cancel', 'alpha')) {
    $action = 'list';
    $massaction = '';
}
if (!GETPOST('confirmmassaction', 'alpha') && $massaction != 'presend' && $massaction != 'confirm_presend') {
    $massaction = '';
}

$parameters = array();
$reshook = $hookmanager->executeHooks('doActions', $parameters, $object, $action); // Note that $action and $object may have been modified by some hooks
if ($reshook < 0) {
    setEventMessages($hookmanager->error, $hookmanager->errors, 'errors');
}

if (empty($reshook)) {
    // Selection of new fields
    include DOL_DOCUMENT_ROOT . '/core/actions_changeselectedfields.inc.php';

    // Purge search criteria
    if (GETPOST('button_removefilter_x', 'alpha') || GETPOST('button_removefilter.x', 'alpha') || GETPOST('button_removefilter', 'alpha')) { // All tests are required to be compatible with all browsers
        $search_ref = '';
        $search_employee = '';
        $search_description = '';
        $search_hdat = '';
        $toselect = array();
        $search_array_options = array();
    }

    if (GETPOST('button_removefilter_x', 'alpha')
        || GETPOST('button_removefilter.x', 'alpha')
        || GETPOST('button_removefilter', 'alpha')
        || GETPOST('button_search_x', 'alpha')
        || GETPOST('button_search.x', 'alpha')
        || GETPOST('button_search', 'alpha')) {
        $massaction = '';
    }
}

// Reset selected fields to include 'hdat' by default
if (isset($_SESSION['listview'][$contextpage]['selectedfields'])) {
    unset($_SESSION['listview'][$contextpage]['selectedfields']);
}

// Define fields to display
$arrayfields = array(
    'cp.ref' => array('label' => 'Ref', 'checked' => 1, 'position' => 5),
    'cp.fk_type' => array('label' => 'Type', 'checked' => 1, 'position' => 10),
    'cp.fk_user' => array('label' => 'Employee', 'checked' => 1, 'position' => 20),
    'cp.date_debut' => array('label' => 'Date', 'checked' => 1, 'position' => 30),
    'cp.hdat' => array('label' => 'Heure debut', 'checked' => 1, 'position' => 35),
    // 'cp.date_fin'    => array('label' => 'DateFinCP', 'checked' => 1, 'position' => 32), // Removed 'cp.date_fin'
    'cp.description' => array('label' => 'Description', 'checked' => -1, 'position' => 800),
    // Removed 'used_days', 'date_start_month', 'date_end_month', 'used_days_month'
);

/*
 * View
 */

$form = new Form($db);
$formother = new FormOther($db);
$holidaystatic = new Holiday($db);

// Removed $listhalfday as it is no longer needed
// $listhalfday = array('morning'=>$langs->trans("Morning"), "afternoon"=>$langs->trans("Afternoon"));

$title = $langs->trans('ATTitreMenu');

llxHeader('', $title);

$search_month = GETPOST("remonth", 'int') ? GETPOST("remonth", 'int') : date("m", time());
$search_year = GETPOST("reyear", 'int') ? GETPOST("reyear", 'int') : date("Y", time());
$year_month = sprintf("%04d", $search_year) . '-' . sprintf("%02d", $search_month);

// Modify the SQL query to include only 'Autorisation' type
$sql = "SELECT cp.rowid, cp.ref, cp.fk_user, cp.date_debut, cp.fk_type, cp.description, cp.halfday, cp.statut as status, cp.hdat";
// Removed cp.date_fin from the SELECT statement
$sql .= " FROM " . MAIN_DB_PREFIX . "holiday cp";
$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "user u ON cp.fk_user = u.rowid";
$sql .= " WHERE cp.rowid > 0";
$sql .= " AND cp.statut = " . Holiday::STATUS_APPROVED;
$sql .= " AND (";
$sql .= " (date_format(cp.date_debut, '%Y-%m') = '" . $db->escape($year_month) . "'";
// Removed date_fin condition as date_fin is no longer used
$sql .= ")";
$sql .= " )";
$sql .= " AND cp.fk_type = '31'"; // Only select 'Autorisation' type
if (!empty($search_ref)) {
    $sql .= natural_search('cp.ref', $search_ref);
}
if (!empty($search_hdat)) { // Added search condition for hdat
    $sql .= natural_search('cp.hdat', $search_hdat);
}
if (!empty($search_employee) && $search_employee > 0) {
    $sql .= " AND cp.fk_user = " . ((int)$search_employee);
}
if (!empty($search_description)) {
    $sql .= natural_search('cp.description', $search_description);
}

$sql .= $db->order($sortfield, $sortorder);

$resql = $db->query($sql);
if (empty($resql)) {
    dol_print_error($db);
    exit;
}

$num = $db->num_rows($resql);

$param = '';
if (!empty($contextpage) && $contextpage != $_SERVER["PHP_SELF"]) {
    $param .= '&contextpage=' . urlencode($contextpage);
}
if ($limit > 0 && $limit != $conf->liste_limit) {
    $param .= '&limit=' . urlencode($limit);
}
if (!empty($search_ref)) {
    $param .= '&search_ref=' . urlencode($search_ref);
}
if (!empty($search_hdat)) { // Added search_hdat to parameters
    $param .= '&search_hdat=' . urlencode($search_hdat);
}
if (!empty($search_employee)) {
    $param .= '&search_employee=' . urlencode($search_employee);
}
if (!empty($search_description)) {
    $param .= '&search_description=' . urlencode($search_description);
}

print '<form method="POST" id="searchFormList" action="' . $_SERVER["PHP_SELF"] . '">';
if ($optioncss != '') {
    print '<input type="hidden" name="optioncss" value="' . $optioncss . '">';
}
print '<input type="hidden" name="token" value="' . newToken() . '">';
print '<input type="hidden" name="formfilteraction" id="formfilteraction" value="list">';
print '<input type="hidden" name="action" value="list">';
print '<input type="hidden" name="sortfield" value="' . $sortfield . '">';
print '<input type="hidden" name="sortorder" value="' . $sortorder . '">';
print '<input type="hidden" name="page" value="' . $page . '">';
print '<input type="hidden" name="contextpage" value="' . $contextpage . '">';

print load_fiche_titre($langs->trans('MenuReportMonth'), '', 'title_hrm');

// Selection filter
print '<div class="tabBar">';
print $formother->select_month($search_month, 'remonth', 0, 0, 'minwidth50 maxwidth75imp valignmiddle', true);
print $formother->selectyear($search_year, 'reyear', 0, 10, 5, 0, 0, '', 'valignmiddle width75', true);
print '<input type="submit" class="button" value="' . dol_escape_htmltag($langs->trans("Search")) . '" />';
print '</div>';
print '<br>';

$moreforfilter = '';

$varpage = empty($contextpage) ? $_SERVER["PHP_SELF"] : $contextpage;
$selectedfields = $form->multiSelectArrayWithCheckbox('selectedfields', $arrayfields, $varpage, getDolGlobalString('MAIN_CHECKBOX_LEFT_COLUMN')); // This also changes content of $arrayfields
$selectedfields .= (count($arrayofmassactions) ? $form->showCheckAddButtons('checkforselect', 1) : '');

print '<div class="div-table-responsive">';
print '<table class="tagtable nobottomiftotal liste">';

print '<tr class="liste_titre_filter">';

// Action column
if (getDolGlobalString('MAIN_CHECKBOX_LEFT_COLUMN')) {
    print '<th class="wrapcolumntitle center maxwidthsearch liste_titre">';
    $searchpicto = $form->showFilterButtons('left');
    print $searchpicto;
    print '</th>';
}

// Filter: Ref
if (!empty($arrayfields['cp.ref']['checked'])) {
    print '<th class="liste_titre">';
    print '<input class="flat maxwidth100" type="text" name="search_ref" value="' . dol_escape_htmltag($search_ref) . '">';
    print '</th>';
}

// Since we are only displaying 'Autorisation', we can disable the Type filter input
if (!empty($arrayfields['cp.fk_type']['checked'])) {
    print '<th class="liste_titre"></th>'; // Leave empty to disable filter input
}

// Filter: Employee
if (!empty($arrayfields['cp.fk_user']['checked'])) {
    print '<th class="liste_titre">';
    print $form->select_dolusers($search_employee, "search_employee", 1, null, 0, '', '', 0, 0, 0, '', 0, '', 'maxwidth100');
    print '</th>';
}

if (!empty($arrayfields['cp.date_debut']['checked'])) {
    print '<th class="liste_titre"></th>';
}

// Added search input for 'hdat'
if (!empty($arrayfields['cp.hdat']['checked'])) {
    print '<th class="liste_titre">';
    print '<input class="flat maxwidth100" type="text" name="search_hdat" value="' . dol_escape_htmltag($search_hdat) . '">';
    print '</th>';
}

// Removed 'cp.date_fin' filter
// if (!empty($arrayfields['cp.date_fin']['checked'])) {
//     print '<th class="liste_titre"></th>';
// }

// Filter: Description
if (!empty($arrayfields['cp.description']['checked'])) {
    print '<th class="liste_titre">';
    print '<input type="text" class="maxwidth100" name="search_description" value="' . dol_escape_htmltag($search_description) . '">';
    print '</th>';
}
// Action column
if (!getDolGlobalString('MAIN_CHECKBOX_LEFT_COLUMN')) {
    print '<th class="liste_titre maxwidthsearch">';
    $searchpicto = $form->showFilterButtons();
    print $searchpicto;
    print '</th>';
}
print '</tr>';

print '<tr class="liste_titre">';
// Action column
if (getDolGlobalString('MAIN_CHECKBOX_LEFT_COLUMN')) {
    print getTitleFieldOfList($selectedfields, 0, $_SERVER["PHP_SELF"], '', '', '', '', $sortfield, $sortorder, 'center maxwidthsearch ') . "\n";
}
if (!empty($arrayfields['cp.ref']['checked'])) {
    print_liste_field_titre($arrayfields['cp.ref']['label'], $_SERVER["PHP_SELF"], 'cp.ref', '', '', '', $sortfield, $sortorder);
}

// Display 'Type' column header
if (!empty($arrayfields['cp.fk_type']['checked'])) {
    print_liste_field_titre($arrayfields['cp.fk_type']['label'], $_SERVER["PHP_SELF"], 'cp.fk_type', '', '', '', $sortfield, $sortorder);
}

if (!empty($arrayfields['cp.fk_user']['checked'])) {
    print_liste_field_titre($arrayfields['cp.fk_user']['label'], $_SERVER["PHP_SELF"], 'cp.fk_user', '', '', '', $sortfield, $sortorder);
}

if (!empty($arrayfields['cp.date_debut']['checked'])) {
    print_liste_field_titre($arrayfields['cp.date_debut']['label'], $_SERVER["PHP_SELF"], 'cp.date_debut', '', '', '', $sortfield, $sortorder, 'center ');
}

// Added table header for 'hdat'
if (!empty($arrayfields['cp.hdat']['checked'])) {
    print_liste_field_titre($arrayfields['cp.hdat']['label'], $_SERVER["PHP_SELF"], 'cp.hdat', '', '', '', $sortfield, $sortorder, 'center ');
}

// Removed 'cp.date_fin' column header
// if (!empty($arrayfields['cp.date_fin']['checked'])) {
//     print_liste_field_titre($arrayfields['cp.date_fin']['label'], $_SERVER["PHP_SELF"], 'cp.date_fin', '', '', '', $sortfield, $sortorder, 'center ');
// }

if (!empty($arrayfields['cp.description']['checked'])) {
    print_liste_field_titre($arrayfields['cp.description']['label'], $_SERVER["PHP_SELF"], 'cp.description', '', '', '', $sortfield, $sortorder);
}
// Action column
if (!getDolGlobalString('MAIN_CHECKBOX_LEFT_COLUMN')) {
    print getTitleFieldOfList($selectedfields, 0, $_SERVER["PHP_SELF"], '', '', '', '', $sortfield, $sortorder, 'center maxwidthsearch ') . "\n";
}
print '</tr>';

if ($num == 0) {
    print '<tr><td colspan="7"><span class="opacitymedium">' . $langs->trans('None') . '</span></td></tr>';
} else {
    while ($obj = $db->fetch_object($resql)) {
        $user = new User($db);
        $user->fetch($obj->fk_user);

        $date_start = $db->jdate($obj->date_debut, true);

        // Leave request
        $holidaystatic->id = $obj->rowid;
        $holidaystatic->ref = $obj->ref;
        $holidaystatic->statut = $obj->status;
        $holidaystatic->status = $obj->status;
        $holidaystatic->fk_user = $obj->fk_user;
        $holidaystatic->fk_type = $obj->fk_type;
        $holidaystatic->hdat = $obj->hdat; // Added hdat to holidaystatic object
        $holidaystatic->description = $obj->description;
        $holidaystatic->halfday = $obj->halfday;
        $holidaystatic->date_debut = $db->jdate($obj->date_debut);
        // Removed date_fin

        print '<tr class="oddeven">';
        // Action column
        if (getDolGlobalString('MAIN_CHECKBOX_LEFT_COLUMN')) {
            print '<td></td>';
        }

        if (!empty($arrayfields['cp.ref']['checked'])) {
            print '<td class="nowraponall">' . $holidaystatic->getNomUrl(1, 1) . '</td>';
        }

        // Display 'Type' column with 'Autorisation'
        if (!empty($arrayfields['cp.fk_type']['checked'])) {
            print '<td>' . $langs->trans('Autorisation') . '</td>';
        }

        if (!empty($arrayfields['cp.fk_user']['checked'])) {
            print '<td class="tdoverflowmax150">' . $user->getFullName($langs) . '</td>';
        }

        if (!empty($arrayfields['cp.date_debut']['checked'])) {
            print '<td class="center">' . dol_print_date($db->jdate($obj->date_debut), 'day');
            print '</td>';
        }

        // Display hdat in table row
        if (!empty($arrayfields['cp.hdat']['checked'])) {
            print '<td class="center">';
            print dol_escape_htmltag($obj->hdat);
            print '</td>';
        }

        // Removed 'cp.date_fin' column data
        // if (!empty($arrayfields['cp.date_fin']['checked'])) {
        //     print '<td class="center">'.dol_print_date($db->jdate($obj->date_fin), 'day');
        //     print '</td>';
        // }

        if (!empty($arrayfields['cp.description']['checked'])) {
            print '<td class="maxwidth300 small">';
            print '<div class="twolinesmax">';
            print dolGetFirstLineOfText(dol_string_nohtmltag($obj->description, 1));
            print '</div>';
            print '</td>';
        }
        // Action column
        if (!getDolGlobalString('MAIN_CHECKBOX_LEFT_COLUMN')) {
            print '<td></td>';
        }
        print '</tr>';
    }
}
print '</table>';
print '</div>';
print '</form>';

// End of page
llxFooter();
$db->close();
