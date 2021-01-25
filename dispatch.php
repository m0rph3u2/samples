<?php

class Dispatch
{

    /**
     * Created on the assumption that there is a SQLConnector Class.
     * Having the MySQL connection in the same class you are using is bad practice. User details should be kept
     * separately
     */

    public function startDispatch()
    {

        /**
         * Creating Daily Batch
         */

        $date = date('Y-m-d');

        $sql = new SQLConnector();

        if ($sql->create("CREATE TABLE dispatch_$date (
                    id INT(6) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    consignment_id VARCHAR(30) NOT NULL,
                    courier_id VARCHAR(30) NOT NULL,
                    address VARCHAR(50),
                    contact_name VARCHAR(50),
                    contact_phone VARCHAR(50),
                    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )")) {
            return json_encode(array('success' => true, 'msg' => 'Dispatch created'));
        } else {
            return json_encode(array('success' => false, 'msg' => 'Something went wrong'));
        }

    }

    public function addConsignment($data)
    {

        /**
         * Adding Consigment to daily batch
         */

        $data = json_decode($data);

        $sql = new SQLConnector();

        if ($sql->insert('dispatch_' . date('Y-m-d'), array(
            'consignment_id' => $this->generteCurrierID($data->courier_id, $data->consignment),
            'courier_id' => $data->courier_id,
            'address' => $data->address,
            'contact_name' => $data->contact_name,
            'contact_phone' => $data->contact_phone
        ))) {
            return json_encode(array('success' => true, 'msg' => 'Dispatch created'));
        } else {
            return json_encode(array('success' => false, 'msg' => 'Something went wrong'));
        }

    }

    public function endCurrentBatch()
    {
        $sql = new SQLConnector();

        $currentBatch = $sql->getAll('dispatch_' . date('Y-m-d'));
        $currierInfo = $sql->getAll('curriers');

        $count = 0;

        foreach ($currierInfo as $info) {

            foreach ($currentBatch as $key => $batch) {
                if ($info->id == $batch->currier_id) {
                    $dispatch[$key] = $batch;
                }
            }

            /**
             *  Switching thru contact methods
             */

            switch ($info->contact_method) {
                case 'email':
                    $connection = $this->sendEmail($info->contact_email, $dispatch);
                    if ($connection == 1) {
                        $count++;
                        $sql->delete('dispatch_' . date('Y-m-d'), $info->id);
                    }
                    break;
                case 'ftp':
                    $connection = $this->sendFtp($info->id, $dispatch);
                    if ($connection == 1) {
                        $count++;
                        $sql->delete('dispatch_' . date('Y-m-d'), $info->id);
                    }
                    break;
                case 'api':
                    $connection = $this->curlApi($info->link, $dispatch);
                    if ($connection == 1) {
                        $count++;
                        $sql->delete('dispatch_' . date('Y-m-d'), $info->id);
                    }
                    break;
                default:
                    return 'No communication details provided!';
            }

        }

        /**
         * If all dispatches have been sent you can remove the daily table
         */

        if ($count == count($currierInfo)) {
            $sql->dropTable('dispatch_' . date('Y-m-d'));
        }

        return json_encode(array('success' => true, 'msg' => 'Current batch ended!'));

    }

    private function sendFtp($info, $dispatch)
    {

        /**
         * Create the file to be uploaded to FTP
         */

        $myfile = fopen("dispatch_" . date('Y-m-d') . ".txt", "w");
        fwrite($myfile, json_encode($dispatch));
        fclose($myfile);

        $sql = new SQLConnector();

        $ftp = $sql->getAll('currier_ftp', $info->id);

        $ftp_server = $ftp->server;

        $ftp_username = $ftp->user;

        $ftp_userpass = $ftp->pwd;

        $file = "dispatch_" . date('Y-m-d') . ".txt";

        $ftp_connection = ftp_connect($ftp_server, 21) or false;

        if ($ftp_connection) {

            $login = ftp_login($ftp_connection,
                $ftp_username, $ftp_userpass);
            if ($login) {

                if (ftp_put($ftp_connection,
                    "uploadedfile_name_in_server.txt", $file, FTP_ASCII)) {
                    return true;
                } else {
                    return false;
                }

            } else {
                return "login failed!";
            }

            ftp_close($ftp_connection);

        }

        /**
         * Deleting file after being sent
         */

        unlink($file);

    }

    private function generteCurrierID($currierId, $consignment)
    {

        $sql = new SQLConnector();

        /**
         * This would get the currier info with where clause
         */

        $currierInfo = $sql->getAll('curriers', $currierId);

        return hash($currierInfo->hash, $consignment);

    }

    private function sendEmail($currierEmail, $data)
    {
        $headers = 'From: dispatch@BobsClothing.co.uk' . "\r\n" .
            'Reply-To: dispatch@BobsClothing.co.uk' . "\r\n" .
            'X-Mailer: PHP/' . phpversion();

        /**
         * Here i am using Arrayinto Class from GIT to convert the array into a table to be easily readable in email
         */

        $obj = new Arrayinto();

        $obj->array_object = $data;
        # Array into table
        $result = $obj->process_table();

        if (mail($currierEmail, 'Daily Dispatch', $result, $headers)) {
            return true;
        } else {
            return false;
        }
    }

    private function curlApi($currierLink, $data)
    {

        $curl = curl_init();

        curl_setopt_array($curl, array(
            CURLOPT_URL => $currierLink,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => "POST",
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => array(
                "Content-Type: application/json"
            ),
        ));

        $response = curl_exec($curl);

        curl_close($curl);

        if (strpos($response, 'error')) {
            return true;
        } else {
            return false;
        }

    }


}
