<?php

/*******************************
 * Author: dungla2011@gmail.com
 * FTP Upload resume-able, (need server resume-able support)
 * Loop upload to complete, big file ... tested with 50 GByte file
 *******************************
 * Sample code Upload
 *******************************

    error_reporting(E_ALL);
    ini_set('display_errors', 1);

    require_once "ftp_lib.php";

    ClassFtpTool::$server = "domain...";
    ClassFtpTool::$port = "...";
    ClassFtpTool::$username = "...";
    ClassFtpTool::$password = "...";
    ClassFtpTool::sample1UploadResume("e:/iso/2019_SERVER_EVAL_x64FRE_en-us_1.iso");

 ********************************
 */


defined("ALPHA") || define('ALPHA', 0.2); // Weight factor of new calculations, between 0 and 1

if (!function_exists("ol1")) {
    function ol1($str)
    {
        echo "\n$str";
    }
}

if (!function_exists("ByteSize")) {
    function ByteSize($bytes, $afterPoint = 2)
    {
        if (!$bytes || !is_numeric($bytes))
            return '0';

        $size = $bytes / 1024;
        if ($size < 1024) {
            $size = number_format($size, $afterPoint);
            $size .= ' KB';
        } else {
            if ($size / 1024 < 1024) {
                $size = number_format($size / 1024, $afterPoint);
                $size .= ' MB';
            } else if ($size / 1024 / 1024 < 1024) {
                $size = number_format($size / 1024 / 1024, $afterPoint);
                $size .= ' GB';
            } else if ($size / 1024 / 1024 / 1024 < 1024) {
                $size = number_format($size / 1024 / 1024 / 1024, $afterPoint);
                $size .= ' TB';
            }
        }

        return $size;
    }
}

class ClassFtpTool
{
    public static $server = "NotSet";
    public static $port = "NotSet";
    public static $username = "NotSet";
    public static $password = "NotSet";
    public static $mode_passive = 1; //1 is passive
    public static $connection;
    public static $documentRootPath;

    static public function initGlx()
    {

        if (!file_exists(DEF_FTP_FOLDER_TRANSFER_SESSION_MARK_STOP))
            mkdir(DEF_FTP_FOLDER_TRANSFER_SESSION_MARK_STOP, 0755, 1);
        if (!file_exists(DEF_FTP_FOLDER_TRANSFER_SESSION_MARK_STOP))
            loi("Error: can not create mark stop folder?" . DEF_FTP_FOLDER_TRANSFER_SESSION_MARK_STOP);

        if (!file_exists(DEF_FTP_FOLDER_TRANSFER_SESSION_LOG))
            mkdir(DEF_FTP_FOLDER_TRANSFER_SESSION_LOG, 0755, 1);
        if (!file_exists(DEF_FTP_FOLDER_TRANSFER_SESSION_LOG))
            loi("Error: can not create log folder?" . DEF_FTP_FOLDER_TRANSFER_SESSION_LOG);

        $objSetting = new ClassSettingSync();
        $objSetting->loadFromIni();

        ClassFtpTool::$server = $objSetting->server;
        ClassFtpTool::$port = $objSetting->port;
        ClassFtpTool::$username = $objSetting->username;
        ClassFtpTool::$password = $objSetting->password;
        ClassFtpTool::$mode_passive = $objSetting->passive_mode;
        //ClassFtpTool::$documentRootPath = trim(file_get_contents($fileRootDoc));
        ClassFtpTool::$documentRootPath = DEF_INSTALL_DIR . "/resources/app/html";

        if (!file_exists(ClassFtpTool::$documentRootPath)) {
            ClassFtpTool::$documentRootPath = DEF_INSTALL_DIR . "/html";
            if (!file_exists(ClassFtpTool::$documentRootPath)) {
                loi("Error: document root path is not set: " . ClassFtpTool::$documentRootPath);
            }
        }

        ClassSettingSync::createTableIfNotExist();
        ClassFtpTransferInfo::createTableIfNotExist();
        ClassFtpTransferJob::createTableIfNotExist();

    }

    static public function getServer($default)
    {
        if (isset(ClassFtpTool::$server) && ClassFtpTool::$server)
            return ClassFtpTool::$server;
        return $default;
    }

    static public function getPort($default = 2121)
    {
        if (isset(ClassFtpTool::$port) && ClassFtpTool::$port)
            return ClassFtpTool::$port;
        return $default;
    }

    static public function getUsername()
    {
        if (isset(ClassFtpTool::$username) && ClassFtpTool::$username)
            return ClassFtpTool::$username;
        return "";
    }

    static public function getPassword()
    {
        if (isset(ClassFtpTool::$password) && ClassFtpTool::$password)
            return ClassFtpTool::$password;
        return "";
    }

    static public function getPassiveMode()
    {
        if (isset(ClassFtpTool::$mode_passive) && ClassFtpTool::$mode_passive)
            return ClassFtpTool::$mode_passive;
        return "";
    }

    static public function connect()
    {
        $connection = @ftp_connect(ClassFtpTool::$server, ClassFtpTool::$port);
        if (!$connection) {
            $err = error_get_last();
            loi("Can not connect to: " . ClassFtpTool::$server . " , Err: " . $err['message'] . " / " . serialize($err));
        }

        $login = @ftp_login($connection, ClassFtpTool::$username, ClassFtpTool::$password);
        if (!$login)
            loi("Can not login?");

        @ftp_pasv($connection, TRUE);
        //Chuyen che do Binary , de command SIZE hoat dong:
        @ftp_raw($connection, "TYPE I");
        ClassFtpTool::$connection = $connection;
    }

    static public function dump()
    {
        $class = new ReflectionClass('ClassFtpTool');
        $arr = $class->getStaticProperties();
        echo "<pre> >>> " . __FILE__ . "(" . __LINE__ . ")<br/>";
        print_r($arr);
        echo "</pre>";
    }

    static public function listDirRecuresive($ftp_stream, $directory)
    {

        $result = [];
        $files = ftp_nlist($ftp_stream, $directory);
        if ($files === false) {
            die("Cannot list $directory");
        }

//        echo "<pre> >>> " . __FILE__ . "(" . __LINE__ . ")<br/>";
//        print_r($files);
//        echo "</pre>";

        foreach ($files as $file) {
            $name = $file;

            //echo "<br/>\n xxx = $name";

            $filepath = $name;
            $filepath = str_replace("//", '/', $filepath);

            $size = ClassFtpTool::getFileSize64($filepath, $ftp_stream);

            //echo "<br/>\n Size = $size";

            if (ClassFtpTool::isFolderExist($name, $ftp_stream)) {
                $result = array_merge($result, ClassFtpTool::listDirRecuresive($ftp_stream, $filepath));
            } else {
                $result[] = $filepath;
            }
        }
        return $result;
    }

    static public function mkDirFtpRecursive($path, $connection)
    {
        //$dir=split("/", $path);
        $path = ClassFtpTool::fixPathName($path);

        $dir = explode("/", $path);
        $path = "";
        $ret = true;

        for ($i = 0; $i < count($dir); $i++) {
            $dir[$i] = trim($dir[$i]);
            if (empty($dir[$i]))
                continue;
            $path .= "/" . $dir[$i];
            //echo "$path\n";
            if (!@ftp_chdir($connection, $path)) {
                ftp_chdir($connection, "/");

                //echo " Mkdir: $path \n";
                if (!ftp_mkdir($connection, $path)) {
                    $err = error_get_last();
                    $tmp = $err['message'];
                    loi(" ERROR create dir: $path  / $tmp ");
                    $ret = false;
                    break;
                }
            }
        }
        return $ret;
    }

    /*
     * Upload ok to GLX, 4S
     */
    static public function sample1UploadResume($source_file)
    {


        if (!ClassFtpTool::$server)
            ClassFtpTool::$server = 'enter domain/ip';
        if (!ClassFtpTool::$port)
            ClassFtpTool::$port = 'enter port';
        if (!ClassFtpTool::$username)
            ClassFtpTool::$username = 'enter username';
        if (!ClassFtpTool::$password)
            ClassFtpTool::$password = 'enter password';

        ClassFtpTool::$mode_passive = 1;

//        if ($err = ClassFtpTool::checkIfNotValidParamConnect()) {
//            die(" Not valid FTP connect info?" . implode(" \r\n", $err));
//        }


        $cc1 = 0;
        $totalUploadByte = 0;
        while (1) {

            if ($cc1) {
                echo "\n Loop reconnect...";
                sleep(5);
            }
            $cc1++;

            $server = ClassFtpTool::$server;
            $ftp_user_name = $username = ClassFtpTool::$username;
            $ftp_user_pass = $password = ClassFtpTool::$password;

            //To upload file
            $primary_connection = ftp_connect($server, ClassFtpTool::$port);
            if (!$primary_connection) {
                $err = error_get_last();
                $errorStr = " *** Error: can not connect1?  " . $err['message'] . "  / Code: " . $err['file'] . " (" . $err['line'] . ")";
                ol1($errorStr);
                continue;

            }

            //To get file size on second connection:
            $secondary_connection = ftp_connect($server, ClassFtpTool::$port);
            if (!$secondary_connection) {
                $err = error_get_last();
                $errorStr = " *** Error: can not connect2?  " . $err['message'] . "  / Code: " . $err['file'] . " (" . $err['line'] . ")";
                ol1($errorStr);
                continue;

            }


            $filesize = filesize($source_file);

//$source_file = 'e:/iso/CentOS-7-x86_64-DVD-15111.iso';
            $destination_file = basename($source_file);

            $mode = FTP_BINARY;
            $login = ftp_login($primary_connection, $ftp_user_name, $ftp_user_pass);
            if (!$login) {
                $err = error_get_last();
                $errorStr = " *** Error: login1? " . $err['message'] . "  / Code: " . $err['file'] . " (" . $err['line'] . ")";
                ol1($errorStr);
                continue;

            }

            $login2 = ftp_login($secondary_connection, $ftp_user_name, $ftp_user_pass);
            if (!$login2) {
                $err = error_get_last();
                $errorStr = " *** Error: login2? " . $err['message'] . "  / Code: " . $err['file'] . " (" . $err['line'] . ")";
                ol1($errorStr);
                continue;

            }

            ftp_pasv($primary_connection, TRUE);
            ftp_pasv($secondary_connection, TRUE);

            //Chuyen che do Binary , de command SIZE hoat dong:
            ftp_raw($secondary_connection, "TYPE I");

            $remoteFullPath = "/";

            ClassFtpTool::chdirRecursiveFtp($remoteFullPath, $primary_connection);

            ClassFtpTool::chdirRecursiveFtp($remoteFullPath, $secondary_connection);

            //get size and Check file exist:
            $sizeNow = ClassFtpTool::getFileSize64($destination_file, $secondary_connection);

            echo "\n Size uploaded = " . ByteSize($sizeNow) . " ($sizeNow) ";

            $upload_status = ftp_nb_put($primary_connection, $destination_file, $source_file, $mode, $sizeNow);

            if ($upload_status == FTP_FAILED) {
                $err = error_get_last();
                $errorStr = " *** Error: login2? " . $err['message'] . "  / Code: " . $err['file'] . " (" . $err['line'] . ")";
                ol1($errorStr);
                continue;
            }

            if ($upload_status == FTP_FINISHED) {
                echo "\n Upload done...?";
                return;
            }

            $t1 = time();

            $countFailed = 0;

            while ($upload_status == FTP_MOREDATA || $upload_status == FTP_FAILED) {

                $upload_status = ftp_nb_continue($primary_connection);

                if ($upload_status == FTP_FAILED) {
                    $countFailed++;
                    ol1(" Upload failed ... " . nowyh());
                    if ($countFailed > 1000) {
                        die("\n Stop Upload error!");
                    }

                    echo "\n Sleep 2";
                    sleep(2);

                    //Kiem tra ket noi:
                    if (!ClassFtpTool::checkConnectionOK($primary_connection)) {
                        echo "\n Error connection1?";
                        break;
                    }
                    if (!ClassFtpTool::checkConnectionOK($secondary_connection)) {
                        echo "\n Error connection2?";
                        break;
                    }
                    continue;
                }

                $countFailed = 0;

                //echo "\n Status = $upload_status";

                if ($upload_status == FTP_FINISHED) {
                    echo "\n Upload done?";
                }

                //Quá 1 giây sau mới tiếp tục
                if (time() == $t1) {
                    continue;
                } else
                    $t1 = time();

                $sizeNow = ClassFtpTool::getFileSize64($destination_file, $secondary_connection);
                if ($sizeNow < 0) {
                    ol1("Error: Can not get filesize-1? ($sizeNow) $destination_file");
                } else {
                    echo "\n Size uploaded = " . ByteSize($sizeNow) ;
                }
            }

            if (ClassFtpTool::checkConnectionOK($secondary_connection) || ClassFtpTool::checkConnectionOK($primary_connection)
                || $upload_status == FTP_FAILED) {
                echo "\n Reloop to reconnect ...";
                sleep(2);
                continue;
            }

            if ($upload_status == FTP_FINISHED) {
                echo "\n Upload done?";
                break;
            }

            echo "\n Upload Status = $upload_status";

            _FORCE_STOP:
            break;
        }
    }

    static public function chdirRecursiveFtp($path, $connection)
    {
        //$dir=split("/", $path);
        $path = ClassFtpTool::fixPathName($path);

        $dir = explode("/", $path);
        $path = "";
        $ret = true;

        if (!ftp_chdir($connection, "/")) {
            loi("Can not change to root folder?");
        }

        for ($i = 0; $i < count($dir); $i++) {
            $dir[$i] = trim($dir[$i]);
            if (empty($dir[$i]))
                continue;
            $path .= "/" . $dir[$i];
            //echo "$path\n";
            if (!@ftp_chdir($connection, $path)) {
                $err = error_get_last();
                $tmp = $err['message'];
                loi(" ERROR chdir: $path / $tmp");
            }
        }
        return $ret;
    }

    static public function fixPathName($path)
    {

        $path = trim($path);
        if (!$path || strlen($path) == 1)
            return $path;

        $path = str_replace("\\", "/", $path);
        $path = str_replace("../", "/", $path);
        for ($i = 0; $i < 10; $i++)
            $path = str_replace("//", "/", $path);

        if ($path[strlen($path) - 1] == '/')
            $path = substr($path, 0, -1);
        if ($path[strlen($path) - 1] == '/')
            $path = substr($path, 0, -1);
        if ($path[strlen($path) - 1] == '/')
            $path = substr($path, 0, -1);

        return $path;
    }

    static public function listFolder($path, $connection = null, $getFileOrFolder = null /*1: fileonly, 2: folder only*/)
    {
        if (!$connection)
            $connection = ClassFtpTool::$connection;


        $path = ClassFtpTool::fixPathName($path);

        if (strstr($path, "/") !== false) {
            $cdir = ftp_pwd($connection);
        }


    }

    static public function isFolderExist($path, $connection = null)
    {

        //$dir=split("/", $path);
        if (!$connection)
            $connection = ClassFtpTool::$connection;

        $path = ClassFtpTool::fixPathName($path);
        //$dir = explode("/", $path);
        //$path = "";
        //$ret = true;

        $cdir = ftp_pwd($connection);

        $ret = true;
        if (!@ftp_chdir($connection, $path))
            $ret = false;

        //Tro ve folder goc:
        ftp_chdir($connection, $cdir);
        return $ret;
    }

    public static function checkConnectionOK($con)
    {
        return is_array(ftp_nlist($con, ".")) ? 1 : 0;
    }

    public static function getFileSize64($filepath, $connect = null)
    {

        if (!$connect)
            $connect = ClassFtpTool::$connection;

        $filepath = str_replace("\\", "/", $filepath);
        $basename = basename($filepath);

        if (strstr($filepath, "/")) {
            $dirPath = dirname($filepath);
            $arrPath = explode("/", $dirPath);
            if ($arrPath && is_array($arrPath) && count($arrPath) > 0) {
                $cdir = ftp_pwd($connect);
                for ($i = 0; $i < count($arrPath); $i++) {
                    $tmp = trim($arrPath[$i]);
                    if (!$tmp)
                        continue;
                    //echo "<br/> tmp = $tmp";
                    if (!ftp_chdir($connect, $arrPath[$i])) {

                        $err = error_get_last();
                        $tmp = $err['message'];

                        echo("\n Can not change to folder: " . $arrPath[$i] . " / $tmp");

                        return 0;
                    }
                }
            }
        }

        $filesizeOnserver = -2;
        //  Kiem tra xem file co ton tai khong:
        $response = ftp_raw($connect, "SIZE $basename");
        //  print_r($response);

        //550 File not found
        if (substr($response[0], 0, 3) == 550) {
            return -1;
        } else {
            if (substr($response[0], 0, 3) == 213) {
                $filesize = floatval(str_replace('213 ', '', $response[0]));
                $filesizeOnserver = $filesize;
            }
        }

        //Change lai folder ban dau:
        if (isset($cdir)) {
            ftp_chdir($connect, $cdir);
        }

        return $filesizeOnserver;
    }
}