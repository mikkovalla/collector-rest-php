<?php

namespace App\Classes;

class Collector {

    var $ok_http_codes = array(200,201,204);
    var $apiurl = "https://api.movenium.com/beta.3/";
    var $access_token = null;
    var $default_client_id = "openapi";
    var $allow_error = false;
    var $headers = array();

    public function set_accesstoken_directly($access_token, $url = null) {
        if ($url) $this->apiurl = $url;
        $this->access_token = $access_token;
    }

    public function login($username, $password, $client_id = null, $url = null) {
        if ($url) $this->apiurl = $url;
        if (!$client_id) $client_id = $this->default_client_id;
        $grant = array(
            "username" => $username,
            "password" => $password,
            "grant_type" => "password",
            "client_id" => $client_id
        );
        $back = $this->request("post", "login", http_build_query($grant));
        //print_r($back);
        $this->access_token = $back['access_token'];

        if ($this->access_token)
            return true;
        else
            return $back;
    }

    public function setHeader($header, $value) {
        $this->headers[$header] = $value;
    }

    /**
     *
     * @param type $form
     * @param type $params
     * @param array $sideload ie. {project: [name,number]}
     * @return type
     */
    public function findAll($form, $params = array()) {
        $form = $this->pluralize($form);

        $sideload = array_key_exists('sideload', $params) ? $params['sideload'] : null;

        if (is_array($sideload)) {
            unset($params['sideload']);
            $params = array_merge($this->create_sideload_get($sideload), $params);
        }

        $path = $this->camelCase($form);
        if (count($params) > 0)
            $path .= '?'.http_build_query($params);

        $back = $this->request("get", $path, $params);

        if ($this->allow_error && array_key_exists('error', $back))
            return $back;

        if (is_array($sideload))
            return $this->populate_sideload($back, $form, $sideload);
        else
            return $back[$form];
    }

    private function create_sideload_get($sideload_arr) {
        $temp = array();
        foreach ($sideload_arr as $field => $subfields) {
            foreach ($subfields as $subfield) {
                $temp[] = $field.".".$subfield;
            }
        }
        return array("sideload" => $temp);
    }

    private function populate_sideload($data, $form, $sideload) {
        $rows = $data[$form];
        $data_by_ids = array();
        if (count($rows) < 1) return $rows;
        foreach ($sideload as $field => $subfields) {
            if(key_exists($this->pluralize($field), $data)){
                foreach ($data[$this->pluralize($field)] as $sidedata) {
                    $data_by_ids[$field][$sidedata['id']] = $sidedata;
                }
            }
        }

        foreach ($rows as $key => $row) {
            foreach ($sideload as $field => $subfields) {
                if(key_exists($field, $data_by_ids)){
                    if(key_exists($row[$field], $data_by_ids[$field])){
                        $rows[$key][$field] = $data_by_ids[$field][$row[$field]];
                    }
                }
            }
        }
        return $rows;
    }

    public function insertRow($form, $values, $params = array()) {
        $values = array($this->camelCase($form) => $values);
        if (array_key_exists("validation", $values) && ['validation'] == "off") $values['validation'] = "off";
        $back = $this->request("post", $this->pluralize_and_camelCase($form), json_encode($values));
        return $back;
    }

    public function removeRow($form, $id) {
        $back = $this->request("delete", $this->pluralize_and_camelCase($form)."/".$id, "");
        return $back;
    }

    public function restoreRow($form, $id) {
        $back = $this->request("put", $this->pluralize_and_camelCase($form)."/".$id, json_encode(array($form => array("row_info.status" => "normal"))));
        return $back;
    }

    public function updateRow($form, $id, $values) {

        if (is_array($id)) {
            $values['id'] = $id;
            $back = $this->request("put", $this->pluralize_and_camelCase($form), json_encode(array($this->camelCase($form) => $values)));
        }
        else {
            $back = $this->request("put", $this->pluralize_and_camelCase($form)."/".$id, json_encode(array($this->camelCase($form) => $values)));
        }
        return $back;
    }

    public function forms($form) {
        return $this->request("get", "forms");
    }

    public function getMe() {
        $this->allow_error = true;
        return $this->request("get", "me");
    }

    public function update() {
        $back = $this->request("post", "update");
        return $back;
    }

    public function request($method, $path, $content = null) {

        $url = $this->apiurl.$path;
        $ch = \curl_init();

        curl_setopt($ch, CURLOPT_URL,$url);

        if ($method == "post")
            curl_setopt($ch, CURLOPT_POST, 1);
        else if ($method == "put")
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
        else if ($method == "delete")
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");

        if ($method == "post" || $method == "put")
            curl_setopt($ch, CURLOPT_POSTFIELDS, $content);

        if ($this->access_token)
            $this->setHeader("Authorization", "Bearer ".$this->access_token);

        $headers = $this->formatHeaders($this->headers);

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $server_output = curl_exec ($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        //print "output: ".$server_output;
        if ($errno = curl_errno($ch)) {
            return array("error" => $errno, "error_description" => curl_error($ch));
        }

        curl_close ($ch);

        if (!$this->allow_error && array_search($httpcode, $this->ok_http_codes) === false) {
            $path_start = explode("?", $path);
            throw new \Exception("Collector API returned ".$server_output." ($httpcode) for $method /".$path_start[0]);
        }

        try {
            $json = json_decode($server_output, true);
        }
        catch (Exception $e) {
            print $e->getMessage();
        }

        return $json === null ? $server_output : (array) $json;
    }

    public function camelCase($form) {
        $parts = explode("_", $form);
        if (count($parts) < 2) return $form;
        return $parts[0].ucfirst($parts[1]);
    }

    public function pluralize($name) {
        if (substr($name,strlen($name) - 1, 1) == "s") return $name;
        return $name."s";
    }

    public function pluralize_and_camelCase($name) {
        return $this->camelCase($this->pluralize($name));
    }

    private function formatHeaders($headers)
    {
        $ret = array();
        foreach($headers as $k => $v) {
            $ret[] = $k.": ".$v;
        }
        return $ret;
    }
}