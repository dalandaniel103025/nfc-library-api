<?php

require_once __DIR__.'/db.php';

if ($conn->connect_error) {
    die("Connection failed");
}

if (!isset($_GET['uid'])) {
    exit("No UID");
}

$uid = strtoupper(trim($_GET['uid']));

/* =========================
   MEMBER SCAN
========================= */

$userQuery = "
SELECT *
FROM users
WHERE UPPER(nfc_uid) = '$uid'
LIMIT 1
";

$userResult = $conn->query($userQuery);

if ($userResult && $userResult->num_rows > 0) {

    $user = $userResult->fetch_assoc();

    /* CLEAR OLD PERSISTENT POPUPS */

    $conn->query("
        DELETE FROM notifications
        WHERE status='persistent'
    ");

    /* CREATE ACTIVE SESSION */

    $updateActive = $conn->query("
        UPDATE active_user
        SET
            user_id=".$user['user_id'].",
            status='waiting_book',
            created_at=NOW()
        WHERE id=1
    ");

    if (!$updateActive) {
        die("ACTIVE USER UPDATE ERROR: " . $conn->error);
    }

    /* CREATE PERSISTENT POPUP */

    $picture = !empty($user['id_picture'])
        ? $user['id_picture']
        : 'id_placeholder.jpg';

    $message =
        "Welcome ".$user['name']."! \nTap a Book to Borrow or Return it!";

    $notif = $conn->query("
        INSERT INTO notifications
        (message, status, image)
        VALUES (
            '$message',
            'persistent',
            '$picture'
        )
    ");

    if (!$notif) {
        die("NOTIFICATION ERROR: " . $conn->error);
    }

    echo "Member: " . $user['name'];

    exit();
}

/* =========================
   BOOK SCAN
========================= */

$bookQuery = "
SELECT *
FROM books
WHERE UPPER(nfc_tag_uid) = '$uid'
LIMIT 1
";

$bookResult = $conn->query($bookQuery);

if ($bookResult && $bookResult->num_rows > 0) {

    $book = $bookResult->fetch_assoc();

    /* =========================
       GET ACTIVE USER
    ========================= */

    $activeUser = $conn->query("
        SELECT *
        FROM active_user
        WHERE id=1
    ");

    if (!$activeUser) {
        die("ACTIVE USER QUERY ERROR: " . $conn->error);
    }

    $activeData = $activeUser->fetch_assoc();

    /* =========================
       INVALID SEQUENCE
    ========================= */

    if (
        !$activeData ||
        !$activeData['user_id'] ||
        $activeData['status'] != 'waiting_book'
    ) {

        $conn->query("
            INSERT INTO notifications
            (message, status, image)
            VALUES (
                'Invalid Operation! Please tap Member NFC Card first.',
                'invalid',
                'id_card.jpeg'
            )
        ");

        echo "Invalid Scan Sequence";

        exit();
    }

    /* =========================
       SESSION TIMEOUT
    ========================= */

    $created = strtotime($activeData['created_at']);

    if ((time() - $created) > 30) {

        $conn->query("
            UPDATE active_user
            SET
                user_id=NULL,
                status=NULL
            WHERE id=1
        ");

        $conn->query("
            DELETE FROM notifications
            WHERE status='persistent'
        ");

        $conn->query("
            INSERT INTO notifications (message)
            VALUES (
                'TRANSACTION SESSION EXPIRED'
            )
        ");

        echo "Session Expired";

        exit();
    }

    $user_id = $activeData['user_id'];

    /* =========================
       RETURN BOOK
    ========================= */

    if ($book['status'] == 'borrowed') {

        /* =========================
        VERIFY CURRENT OWNER
        ========================= */

        $latestBorrow = $conn->query("
            SELECT *
            FROM transactions
            WHERE
                book_id = ".$book['id']."
            ORDER BY id DESC
            LIMIT 1
        ");

        if($latestBorrow && $latestBorrow->num_rows > 0){

            $borrowData = $latestBorrow->fetch_assoc();

            /* CHECK IF DIFFERENT USER */

            if($borrowData['user_id'] != $user_id){

                /* REMOVE WAITING NOTIFICATION */

                $conn->query("
                    DELETE FROM notifications
                    WHERE status='persistent'
                ");

                /* INVALID OWNER */

                $conn->query("
                    INSERT INTO notifications
                    (message, status, image)
                    VALUES (
                        'Invalid Transaction.
        Book is Registered to a Different User.',
                        'invalid',
                        'transaction_failed.jpg'
                    )
                ");

                echo "INVALID OWNER DETECTED";

                die();
            }
        }

        $returnBook = $conn->query("
            UPDATE books
            SET status='available'
            WHERE id=".$book['id']."
        ");

        if (!$returnBook) {
            die("RETURN BOOK ERROR: " . $conn->error);
        }

        /* INSERT RETURN TRANSACTION */

        $returnLog = $conn->query("
            INSERT INTO transactions
            (user_id, book_id, status)
            VALUES
            ($user_id, ".$book['id'].", 'returned')
        ");

        if (!$returnLog) {
            die("RETURN LOG ERROR: " . $conn->error);
        }

        /* UPDATE USER BOOK COUNT */

        $updateReturn = $conn->query("
            UPDATE users
            SET books_borrowed =
                CASE
                    WHEN books_borrowed > 0
                    THEN books_borrowed - 1
                    ELSE 0
                END
            WHERE user_id = $user_id
        ");

        if (!$updateReturn) {
            die("RETURN COUNT UPDATE ERROR: " . $conn->error);
        }

        /* REMOVE ACTIVE SESSION */

        $conn->query("
            UPDATE active_user
            SET
                user_id=NULL,
                status=NULL
            WHERE id=1
        ");

        /* REMOVE PERSISTENT POPUP */

        $conn->query("
            DELETE FROM notifications
            WHERE status='persistent'
        ");

        /* COMPLETE NOTIFICATION */

        $conn->query("
            INSERT INTO notifications
            (message, status, image)
            VALUES (
                'Returned ".$book['title']."!
        Thank You for Using Our Services!',
                'temporary',
                '".$book['cover_image']."'
            )
        ");

        echo "Book Returned";

        exit();
    }

    /* =========================
       BORROW BOOK
    ========================= */

    $borrowBook = $conn->query("
        UPDATE books
        SET status='borrowed'
        WHERE id=".$book['id']."
    ");

    if (!$borrowBook) {
        die("BORROW BOOK ERROR: " . $conn->error);
    }

    $due_date = date("Y-m-d H:i:s", strtotime("+7 days"));

    /* INSERT BORROW TRANSACTION */

    $borrowLog = $conn->query("
        INSERT INTO transactions
        (user_id, book_id, due_date, status)
        VALUES
        ($user_id, ".$book['id'].", '$due_date', 'borrowed')
    ");

    if (!$borrowLog) {
        die("BORROW LOG ERROR: " . $conn->error);
    }

    /* UPDATE USER BOOK COUNT */

    $updateBorrow = $conn->query("
        UPDATE users
        SET books_borrowed = books_borrowed + 1
        WHERE user_id = $user_id
    ");

    if (!$updateBorrow) {
        die("BOOK COUNT UPDATE ERROR: " . $conn->error);
    }

    /* REMOVE ACTIVE SESSION */

    $conn->query("
        UPDATE active_user
        SET
            user_id=NULL,
            status=NULL
        WHERE id=1
    ");

    /* REMOVE PERSISTENT POPUP */

    $conn->query("
        DELETE FROM notifications
        WHERE status='persistent'
    ");

        /* COMPLETE NOTIFICATION */

        $conn->query("
            INSERT INTO notifications
            (message, status, image)
            VALUES (
                'Borrowed ".$book['title']."!
        Thank You for Using Our Services!',
                'temporary',
                '".$book['cover_image']."'
            )
        ");

    echo "Borrowed: " . $book['title'];

    exit();
}

echo "Unknown UID";

?>