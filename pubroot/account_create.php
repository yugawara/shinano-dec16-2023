<?php

declare(strict_types=1);

include_once(__DIR__ . "/../lib/common.php");
include_once(__DIR__ . "/../lib/form_check.php");
include_once(__DIR__ . '/../lib/transactions.php');


// CSRF
//$csrf->getToken();


// fill variables by POSTed values

$form_accessors = ["name", "email", "password_first", "password_check"];
$post_data = array_map(fn($accessor) => $_POST[$accessor], $form_accessors);
[$post_name, $post_email, $post_password_first, $post_password_check] = $post_data;


// Insert DataBase of new user. If POST is safe and unique_email.

function check_for_user_post($name, $email, $password1, $password2){
    return  [\FormCheck\check_user_name_safe($name), 
             \FormCheck\check_user_email_safe_and_unique($email),
             \FormCheck\check_user_password_safe($password1, $password2)];
}


$state_create_account = "creating";

if($request_method == "POST"){
    // check CSRF
    if(!$csrf->checkToken()){
        $csrf_message = "invalid token. use form.\n";
        
    } else {
        // check POSTed form's values
        [[$checked_name, $form_message_name],
         [$checked_email, $form_message_email],
         [$checked_password, $form_message_password]]
        = check_for_user_post($post_name, $post_email, $post_password_check, $post_password_first);

        $safe_form_post_p =
            ($checked_name!=null && $checked_email!=null && $checked_password!=null);
        
        // reigster to user table if good POST.
        if($safe_form_post_p){
            $checked_hashed_password = password_hash($checked_password, PASSWORD_DEFAULT);

            // register user to DB.
            global $data_source_name, $sql_rw_user, $sql_rw_pass;
            \Tx\with_connection($data_source_name, $sql_rw_user, $sql_rw_pass)(
                function($conn_rw) use($checked_name, $checked_email, $checked_hashed_password) {
                    \TxSnn\add_user
                    ($conn_rw, $checked_name, $checked_email, $checked_hashed_password, "");});
            // ask user's public_uid to (newer) DB.
            $new_user_public_uid
            = \Tx\with_connection($data_source_name, $sql_ro_user, $sql_ro_pass)(
                function($conn_ro) use($checked_email) {
                    return \TxSnn\user_public_uid_get_by_email($conn_ro, $checked_email);});

            if($new_user_public_uid){
                $login->login($new_user_public_uid);
                $state_create_account="just_created";
            }else{
                $db_message_tml = "<pre> somewhy failed to regist you.</pre> <br />\n";
            }
        }
    }
}



/*
   $debug_tml=<<<DEBUG_TML
   name  : ${checked_name} , ${form_message_name} <br />
   email : ${checked_email} , ${form_message_email} <br />
   password: ${checked_password} , ${form_message_password} <br />
   DEBUG_TML;
 */

// parepare and execute DB and SQL

// make contents

if($state_create_account=="just_created"){
    $account_create_form_html = "you have registered.\n";
}elseif($state_create_account=="creating"){
    // CSRF inserting html
    $csrf_html = $csrf->hiddenInputHTML();
    // actual content
    $account_create_form_html = <<<ACCOUNT_CREATE_FORM
${db_message_tml}
To create account, name, email and password are needed. <br />
<pre> {$csrf_message} </pre>
<form action="" method="post">
  ${csrf_html}
  <dl>
    <dt> name </dt>
    <dd> <input type="text" name="name" required value="${post_name}"> </input> </dd>
    <dd> <pre>{$form_message_name}</pre> </dd>
    <dt> email </dt>
    <dd> <input type="text" name="email" required value="${post_email}"> </input> </dd>
    <dd> <pre>{$form_message_email}</pre> </dd>
    <dt> password </dt>
    <dd> <input type="password" name="password_first" required value=""> </input> </dd>
    <dt> password for check </dt>
    <dd> <input type="password" name="password_check" required value=""> </input> </dd>
    <dd> <pre>{$form_message_password}</pre> </dd>
  </dl>
  <input type="submit" value="Check for Create Account"> </input>
</form>
ACCOUNT_CREATE_FORM;
}


// prepare template

$content_actual = <<<CONTENT_CREATE_ACCOUNT
${debug_tml}
<h3> Create Account </h3>
{$account_create_form_html}
CONTENT_CREATE_ACCOUNT;


RenderByTemplate("template.html", "Account Create - Shinano -",
                 $content_actual);



?>
