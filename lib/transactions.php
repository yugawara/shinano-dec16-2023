<?php

declare(strict_types=1);

#----------------------------------------------------------------

# lib to provide transaction sequence
namespace Tx {

use \PDO;

class Exception extends \RuntimeException {
}

## read_only : with_connection 内で1つ以上の SELECT 等を行なう.
## read_write: with_connection 内で1つ以上のトランザクション ( \Tx\block ) を行なう.
function with_connection(string $data_source_name, string $sql_user, string $sql_password) {
    return function($tx) use($data_source_name, $sql_user, $sql_password) {
        $conn = new PDO($data_source_name, $sql_user, $sql_password,
                        array(
                            /* connection pooling
                               mysqlドライバでは prepared-statement の leak を防ぐ仕組みは担保されているか? */
                            PDO::ATTR_PERSISTENT => true,
                            PDO::ATTR_TIMEOUT => 600,
                            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                            /* default is buffering all result of query*/
                            PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false)
                        );
        try {
            return $tx($conn);
        } catch(Throwable $e) {
            $conn->rollBack();
            throw $e;
        }
    };
}

function block(PDO $conn, string $tag) {
    return function($body) use($conn, $tag) {
        try {
            if (!($conn->beginTransaction())) {
                throw new \Tx\Exception('Tx.block: beginTrascaction failed: ' . $tag);
            }
            $result = $body();
            if (!($conn->commit())) {
                throw new \Tx\Exception('Tx.block: commit failed: ' . $tag);
            }
            return $result;
        } catch(Throwable $e) {
            $conn->rollBack();
            throw $e;
        }
    };
}

} ## end of namesapce Tx

#----------------------------------------------------------------

# transaction definitions for shinano
namespace TxSnn {

include_once(__DIR__ . '/finite_field.php');

use \PDO;

function unsafe_update_public_uid(PDO $conn, string $tag, $update_callback) {
    return \Tx\block($conn, "unsafe_update_public_uid: " . $tag)(
        ## public_uid を発行するトランザクション
        ## Galois LFSR 以外の $update_callback を渡すと空間を書き潰す危険がある
        function() use($conn, $update_callback) {
            $pstate = $conn->prepare('SELECT last_uid FROM public_uid_state LIMIT 1 FOR UPDATE');
            $pstate->execute();
            ## カーソル位置でテーブルのレコードをロック
            $state_ref = $pstate->fetch(PDO::FETCH_NUM);
            if (!$state_ref) {
                throw new \Tx\Exception('TxSnn.unsafe_update_public_uid: internal error. wrong system initialization');
            }
            $last_public_uid = $state_ref[0];
            [ $public_uid, $result ] = $update_callback($last_public_uid);
            $stmt = $conn->prepare('UPDATE public_uid_state SET last_uid = ?');
            $pstate->fetchAll(); ## UGLY HACK for MySQL!! breaks critical section for Cursor Solid isolation RDBMs
            $stmt->execute(array($public_uid));
            return $result;
        });
}

function gen_public_uid_list(PDO $conn, int $n) {
    return unsafe_update_public_uid($conn, "gen_public_uid_list",
                                    fn ($last_public_uid) => \FF\galois_next24_list($last_public_uid, $n) );
}

function add_user(PDO $conn, string $name, string $email, string $passwd_hash, string $note) {
    $public_uid = unsafe_update_public_uid($conn, "gen_public_uid",
                                           function ($last_public_uid) {
                                               $next = \FF\galois_next24($last_public_uid);
                                               return [ $next, $next ];
                                           });

    \Tx\block($conn, "add_user")(
        function() use($conn, $email, $passwd_hash, $public_uid, $name, $note) {
            $stmt = $conn->prepare(<<<SQL
INSERT INTO user(email, passwd_hash, public_uid, name, note, created_at, updated_at)
    VALUES (:email, :passwd_hash, :public_uid, :name, :note, current_timestamp, current_timestamp)
SQL
            );
            $stmt->execute(array(':email' => $email, ':passwd_hash' => $passwd_hash, 'public_uid' => $public_uid,
                                 ':name' => $name, ':note' => $note));
        });
}

function add_job_listing(PDO $conn, string $email, string $title, string $description) {
    return add_job_things('L')($conn, $email, $title, $description);
}

function add_job_seeking(PDO $conn, string $email, string $title, string $description) {
    return add_job_things('S')($conn, $email, $title, $description);
}

function add_job_things(string $attribute) {
    return function(PDO $conn, string $email, string $title, string $description) use($attribute) {
        \Tx\block($conn, "add_job_things:" . $attribute)(
            function() use($attribute, $conn, $email, $title, $description) {
                $user_id = user_id_lock_by_email_or_raise('TxSnn.add_job_things: ', $conn, $email);
                $stmt = $conn->prepare(<<<SQL
INSERT INTO job_entry(attribute, user, title, description, created_at, updated_at)
    VALUES (:attribute, :user_id, :title, :desc, current_timestamp, current_timestamp)
SQL
                );
                $stmt->execute(array(':attribute' => $attribute, ':user_id' => $user_id, ':title' => $title, ':desc' => $description));
            }
        );
    };
}

function add_job_thing_in_open_or_close(PDO $conn, string $email, string $attribute, string $title, string $description, string $open_or_close){

    $open_close = (( $open_or_close=='open') ? 'open' :
                   (($open_or_close=='close') ? 'close' : 'NULL'));

    return \Tx\block($conn, "add_job_things:" . $attribute)(
        function() use($conn, $email, $attribute, $title, $description, $open_close) {
            // raise if invalid user
            $user_id = user_id_lock_by_email_or_raise('TxSnn.add_job_things: ', $conn, $email);
            // INSERT to DB
            $open_close_query 
                = (( $open_close=='open')  ? "current_timestamp, NULL" :
                   (($open_close=='close') ? "NULL, current_timestamp" : "NULL, NULL"));

            $sql1 = "INSERT job_entry(attribute, user, title, description, "
                  . "                 created_at, updated_at, opened_at, closed_at)"
                  . "  VALUES (:attribute , :user_id, :title, :desc, "
                  . "          current_timestamp, current_timestamp, "
                  . $open_close_query . ");";
            $stmt = $conn->prepare($sql1);
            $stmt->execute(array(':attribute'=>$attribute, ':user_id'=>$user_id,
                                 ':title'=>$title, ':desc'=>$description));
            return true;
        });
}

function open_job_thing(PDO $conn, string $email, int $entry_id){
    \Tx\block($conn, "open_job_thing ID: " . $entry_id)(
        function() use($conn, $email, $entry_id) {
            // raise if invalid user
            $user_id = user_id_lock_by_email_or_raise('TxSnn.open_job_thing: ', $conn, $email);
            // rewrite DB
            $sql1 = "UPDATE job_entry AS J"
                  . "  SET opened_at = current_timestamp, updated_at = current_timestamp"
                  . "  WHERE id = :job_entry_id AND user = :user_id;";
            $stmt = $conn->prepare($sql1);
            $stmt->execute(array(':job_entry_id'=>$entry_id, ':user_id'=>$user_id));
            return true;
        });
}

function close_job_thing(PDO $conn, string $email, int $entry_id){
    \Tx\block($conn, "close_job_thing ID: " . $entry_id)(
        function() use($conn, $email, $entry_id) {
            // raise if invalid user
            $user_id = user_id_lock_by_email_or_raise('TxSnn.close_job_thing: ', $conn, $email);
            // rewrite DB
            $sql1 = "UPDATE job_entry AS J"
                  . "  SET closed_at = current_timestamp, updated_at = current_timestamp"
                  . "  WHERE id = :job_entry_id AND user = :user_id;";
            $stmt = $conn->prepare($sql1);
            $stmt->execute(array(':job_entry_id'=>$entry_id, ':user_id'=>$user_id));
            return true;
        });
}


function update_job_things(PDO $conn, int $entry_id,
                           string $email, string $attribute, string $title, string $description){
    // attribute:: 'S'eeking or 'L'isting or etc...
    // email: email of logging in account.
    \Tx\block($conn, "update_job_things ID: " . $entry_id)(
        function() use($conn, $entry_id, $attribute, $email, $title, $description) {
            // raise if invalid user
            $user_id = user_id_lock_by_email_or_raise('TxSnn.add_job_things: ', $conn, $email);
            // rewrite DB
            $sql1 = "UPDATE job_entry AS J"
                  . "  SET J.attribute = :attribute , J.title = :title , J.description = :description , J.updated_at = current_timestamp "
                  . "  WHERE J.id = :entry_id AND J.user = :user_id;";
            $stmt = $conn->prepare($sql1);
            $stmt->execute(array(':entry_id'=>$entry_id, ':user_id'=>$user_id, ':attribute'=>$attribute, ':title'=>$title, ':description'=>$description));
            return true;
        });
}

function open_job_listing(PDO $conn, string $email, int $job_entry_id) {
    return open_job_things('L')($conn, $email, $job_entry_id);
}

function open_job_seeking(PDO $conn, string $email, int $job_entry_id) {
    return open_job_things('S')($conn, $email, $job_entry_id);
}

function open_job_things(string $attribute) {
    return function(PDO $conn, string $email, int $job_entry_id) use ($attribute) {
        \Tx\block($conn, "open_job_things:" . $attribute)(
            function() use($attribute, $conn, $email, $job_entry_id) {
                $user_id = user_id_lock_by_email_or_raise('TxSnn.open_job_things: ', $conn, $email);
                $stmt = $conn->prepare(<<<SQL
UPDATE job_entry AS J SET opened_at = current_timestamp
       WHERE attribute = :attribute AND id = :job_entry_id AND user = :user_id
SQL
                );
                $stmt->execute(array(':attribute' => $attribute, ':job_entry_id' => $job_entry_id, ':user_id' => $user_id));
            }
        );
    };
}

function close_job_listing(PDO $conn, string $email, int $job_entry_id) {
    return close_job_things('L')($conn, $email, $job_entry_id);
}

function close_job_seeking(PDO $conn, string $email, int $job_entry_id) {
    return close_job_things('S')($conn, $email, $job_entry_id);
}

function close_job_things(string $attribute) {
    return function(PDO $conn, string $email, int $job_entry_id) use ($attribute) {
        \Tx\block($conn, "close_job_things:" . $attribute)(
            function() use($attribute, $conn, $email, $job_entry_id) {
                $user_id = user_id_lock_by_email_or_raise('TxSnn.close_job_things: ', $conn, $email);
                $stmt = $conn->prepare(<<<SQL
UPDATE job_entry AS J SET closed_at = current_timestamp
       WHERE attribute = :attribute AND id = :job_entry_id AND user = :user_id
SQL
                );
                $stmt->execute(array(':attribute' => $attribute, ':job_entry_id' => $job_entry_id, ':user_id' => $user_id));
            }
        );
    };
}

function user_id_lock_by_email_or_raise(string $prefix, PDO $conn, string $email) {
    $user_id = user_id_lock_by_email($conn, $email);
    if (!$user_id) {
        throw new \Tx\Exception($prefix . 'wrong input email: ' . $email);
    }
    return $user_id;
}

function user_id_lock_by_email(PDO $conn, string $email) {
    $stmt = $conn->prepare('SELECT id FROM user WHERE email = ? FOR UPDATE');
    $stmt->execute(array($email));
    // カーソル位置で user テーブルのレコードをロック
    $aref = $stmt->fetch(PDO::FETCH_NUM);
    if ($aref) {
        return $aref[0];
    }
    return false;
}

function user_public_uid_get_by_email(PDO $conn, string $email){
    $stmt = $conn->prepare('SELECT public_uid FROM user WHERE email = ?');
    $stmt->execute(array($email));
    $aref = $stmt->fetch(PDO::FETCH_NUM);
    if ($aref) {
        return $aref[0];
    }
    return false;
}

function view_job_things_by_public_uid(PDO $conn, int $public_uid) {
    $stmt = $conn->prepare(<<<SQL
SELECT U.public_uid, U.name, J.attribute, J.title, J.description, J.created_at, J.updated_at, J.opened_at, J.closed_at, J.id AS eid
       FROM user as U INNER JOIN job_entry AS J
       ON U.id = J.user
       WHERE U.public_uid = ?
       ORDER BY J.attribute, J.opened_at IS NULL ASC, J.created_at ASC;
SQL
            );
    $stmt->execute(array($public_uid));
    return $stmt;
}

function view_job_things_by_email(PDO $conn, string $email) {
    $stmt = $conn->prepare(<<<SQL
SELECT U.public_uid, U.name, J.attribute, J.title, J.description, J.created_at, J.updated_at, J.opened_at, J.closed_at, J.id AS eid
       FROM user as U INNER JOIN job_entry AS J
       ON U.id = J.user
       WHERE U.email = ?
       ORDER BY J.attribute, J.opened_at IS NULL ASC, J.created_at ASC;
SQL
            );
    $stmt->execute(array($email));
    return $stmt;
}

function search_job_things(PDO $conn, string $search_pat) {
    $stmt = $conn->prepare(<<<SQL
SELECT U.public_uid, U.name, J.attribute, J.title, J.description, J.created_at, J.updated_at, J.opened_at, J.closed_at
       FROM user as U INNER JOIN job_entry AS J
       ON U.id = J.user
       WHERE J.title LIKE CONCAT('%', ?, '%')
       ORDER BY J.attribute, J.user, J.opened_at IS NULL ASC, J.created_at ASC;
SQL
    );
    $stmt->execute(array($search_pat));
    return $stmt;
}

} ## end of namesapce TxSnn

?>
