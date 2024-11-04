<?php
/* [Existing comments and header remain unchanged] */

/**
 *       \file       htdocs/holiday/document.php
 *       \ingroup    holiday
 *       \brief      Page for attached documents on holiday requests
 */

// Load Dolibarr environment
require '../../main.inc.php';
require_once DOL_DOCUMENT_ROOT.'../holiday/class/holiday.class.php';
require_once DOL_DOCUMENT_ROOT.'../core/lib/files.lib.php';
require_once DOL_DOCUMENT_ROOT.'../core/lib/images.lib.php';
require_once DOL_DOCUMENT_ROOT.'../core/lib/date.lib.php';
require_once DOL_DOCUMENT_ROOT.'../core/lib/holiday.lib.php';
require_once DOL_DOCUMENT_ROOT.'../core/class/html.formfile.class.php';

// Load translation files required by the page
$langs->loadLangs(array('other', 'holiday', 'companies'));

$id = GETPOST('id', 'int');
$ref = GETPOST('ref', 'alpha');
$action = GETPOST('action', 'aZ09');
$confirm = GETPOST('confirm', 'alpha');

// Get parameters
$limit = GETPOST('limit', 'int') ? GETPOST('limit', 'int') : $conf->liste_limit;
$sortfield = GETPOST('sortfield', 'aZ09comma');
$sortorder = GETPOST('sortorder', 'aZ09comma');
$page = GETPOSTISSET('pageplusone') ? (GETPOST('pageplusone') - 1) : GETPOST("page", 'int');
if (empty($page) || $page == -1) {
    $page = 0;
}
$offset = $limit * $page;
$pageprev = $page - 1;
$pagenext = $page + 1;
if (!$sortorder) {
    $sortorder = "ASC";
}
if (!$sortfield) {
    $sortfield = "position_name";
}

$childids = $user->getAllChildIds(1);

$morefilter = '';
if (!empty($conf->global->HOLIDAY_HIDE_FOR_NON_SALARIES)) {
    $morefilter = 'AND employee = 1';
}

$object = new Holiday($db);

$extrafields = new ExtraFields($db);

// fetch optional attributes and labels
$extrafields->fetch_name_optionals_label($object->table_element);

if (($id > 0) || $ref) {
    $object->fetch($id, $ref);

    // Check current user can read this leave request
    $canread = 0;
    if (!empty($user->rights->holiday->readall)) {
        $canread = 1;
    }
    if (!empty($user->rights->holiday->read) && in_array($object->fk_user, $childids)) {
        $canread = 1;
    }
    if (!$canread) {
        accessforbidden();
    }
}

$upload_dir = $conf->holiday->dir_output.'/'.get_exdir(0, 0, 0, 1, $object, '');
$modulepart = 'holiday';

// Protection if external user
if ($user->socid) {
    $socid = $user->socid;
}
$result = restrictedArea($user, 'holiday', $object->id, 'holiday');

$permissiontoadd = $user->rights->holiday->write; // Used by the include of actions_setnotes.inc.php

/*
 * Actions
 */

include DOL_DOCUMENT_ROOT.'/core/actions_linkedfiles.inc.php';

/*
 * View
 */

$form = new Form($db);
$formfile = new FormFile($db);

$title = $langs->trans("Leave").' - '.$langs->trans("Files");

llxHeader('', $title);

if ($object->id) {
    $valideur = new User($db);
    $valideur->fetch($object->fk_validator);

    $userRequest = new User($db);
    $userRequest->fetch($object->fk_user);

    $head = holiday_prepare_head($object);

    print dol_get_fiche_head($head, 'documents', $langs->trans("CPTitreMenu"), -1, 'holiday');

    // Build file list
    $filearray = dol_dir_list($upload_dir, "files", 0, '', '(\.meta|_preview.*\.png)$', $sortfield, (strtolower($sortorder) == 'desc' ?SORT_DESC:SORT_ASC), 1);
    $totalsize = 0;
    foreach ($filearray as $key => $file) {
        $totalsize += $file['size'];
    }

    $linkback = '<a href="'.DOL_URL_ROOT.'/holiday/list.php?restore_lastsearch_values=1">'.$langs->trans("BackToList").'</a>';

    dol_banner_tab($object, 'ref', $linkback, 1, 'ref', 'ref');

    print '<div class="fichecenter">';
    print '<div class="underbanner clearboth"></div>';

    print '<table class="border tableforfield centpercent">';

    print '<tr>';
    print '<td class="titlefield">'.$langs->trans("User").'</td>';
    print '<td>';
    print $userRequest->getNomUrl(-1, 'leave');
    print '</td></tr>';

    // Type
    print '<tr>';
    print '<td>'.$langs->trans("Type").'</td>';
    print '<td>';
    print $langs->trans('Autorisation');
    print '</td>';
    print '</tr>';

    // Start Date
    print '<tr>';
    print '<td>'.$langs->trans('DateDebCP').'</td>';
    print '<td>'.dol_print_date($object->date_debut, 'day');
    print '</td>';
    print '</tr>';

    // hdat (Start Hour)
    print '<tr>';
    print '<td>'.$langs->trans('StartHour').'</td>';
    print '<td>'.dol_escape_htmltag($object->hdat).'</td>';
    print '</tr>';

    // Description
    print '<tr>';
    print '<td>'.$langs->trans('DescCP').'</td>';
    print '<td>'.nl2br($object->description).'</td>';
    print '</tr>';

    print '<tr><td>'.$langs->trans("NbOfAttachedFiles").'</td><td colspan="3">'.count($filearray).'</td></tr>';
    print '<tr><td>'.$langs->trans("TotalSizeOfAttachedFiles").'</td><td colspan="3">'.dol_print_size($totalsize, 1, 1).'</td></tr>';

    print '</tbody>';
    print '</table>'."\n";

    print '</div>';

    print '<div class="clearboth"></div>';

    print dol_get_fiche_end();

    $permissiontoadd = $user->rights->holiday->write;
    $permtoedit = $user->rights->holiday->write;
    $param = '&id='.$object->id;
    $relativepathwithnofile = dol_sanitizeFileName($object->ref).'/';
    $savingdocmask = dol_sanitizeFileName($object->ref).'-__file__';

    // Include the linked files actions
    include DOL_DOCUMENT_ROOT.'/core/tpl/document_actions_post_headers.tpl.php';
} else {
    print $langs->trans("ErrorUnknown");
}

// End of page
llxFooter();
$db->close();
