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
