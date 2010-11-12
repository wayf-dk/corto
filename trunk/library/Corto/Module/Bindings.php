<?php

/**
 * @internal include the abstract baseclass
 */
require_once 'Abstract.php';

/**
 * Class for binding module specific exceptions.
 * @author Boy
 */
class Corto_Module_Bindings_Exception extends Corto_ProxyServer_Exception {

}

/**
 * Class for binding module verification exceptions.
 * @author Boy
 */
class Corto_Module_Bindings_VerificationException extends Corto_Module_Bindings_Exception {

}

/**
 * The bindings module for Corto, which implements support for various data
 * bindings.
 * @author Boy
 */
class Corto_Module_Bindings extends Corto_Module_Abstract {
    const ARTIFACT_BINARY_FORMAT = 'ntypecode/nendpointindex/H40sourceid/H40messagehandle';

    const SAMLArt = 'SAMLArt';
    const SAMLRequest = 'SAMLRequest';
    const SAMLResponse = 'SAMLResponse';
    const LogoutRequest = 'LogoutRequest';
    const LogoutResponse = 'LogoutResponse';

    /**
     * Supported bindings in Corto.
     * @var array
     */
    protected $_bindings = array(
        'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect' => 'HTTPRedirect',
        'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST' => 'HTTPPost',
        //'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST-SimpleSign'    => 'HTTPPostSimpleSign',
        //'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Artifact'          => 'HTTPArtifact',
        //'urn:oasis:names:tc:SAML:2.0:bindings:URI'                    => 'URI',

        'urn:oasis:names:tc:SAML:2.0:bindings:SOAP' => 'SOAP',
        'INTERNAL' => 'Internal',
        'JSON-Redirect' => 'JSONHTTPRedirect',
        'JSON-POST' => 'JSONHTTPPost',
        'MDRequest' => 'MDRequest',
        null => 'HTTPRedirect',
    );

    /**
     * Process an incoming SAML request message. The data is retrieved automatically
     * depending on the binding used.
     */
    public function receiveRequest($params, $key = self::SAMLRequest)
    {
        $function = '_receive' . $this->_bindings[$params['Binding']];
        $request = $this->$function($key, $params);
        if (nvl($request, '__t') == self::SAMLRequest) {
            $request['_ForceAuthn'] = isset($request['_ForceAuthn']) && ($request['_ForceAuthn'] == 'true' || $request['_ForceAuthn'] == '1');
            $request['_IsPassive'] = isset($request['_IsPassive']) && ($request['_IsPassive'] == 'true' || $request['_IsPassive'] == '1');
            $this->_server->getSessionLog()->debug("Received request: " . var_export($request, true));
        }
        $request['__']['Binding'] = $params['Binding'];
        $this->_verifyRequest($request);

        return $request;
    }

    /**
     * Process an incoming SAML response message.
     */
    public function receiveResponse($params)
    {
        $function = '_receive' . $this->_bindings[$params['Binding']];
        $response = $this->$function(self::SAMLResponse, $params);
        $this->_server->getSessionLog()->debug("Received response: " . var_export($response, true));

        $this->_decryptResponse($response);
        $this->_verifyResponse($response);
        $this->_server->saveSloInfo($response);
        return $response;
    }

    /**
     * Process an incoming logout request or response message.
     */
    public function receiveLogoutMessage($params)
    {
        $function = '_receive' . $this->_bindings[$params['Binding']];
        if ($message = $this->$function(self::LogoutRequest, $params)) {
            $this->_verifyRequest($message);
        } else {
            $message = $this->$function(self::LogoutResponse, $params);
        }
        $this->_server->getSessionLog()->debug("Received logout Messsage: " . var_export($message, true));
        return $message;
    }

    /**
     * Receive a message using the 'internal' binding ie. don't send
     * the response out of Corto. Used between two co-hosted entities.
     *
     * @todo make clear that co-hosting demands access to commmon cookie!
     *
     * @param  $key
     * @return
     */

    protected function _receiveMessageFromInternalBinding($key)
    {
        return $_REQUEST[$key];
    }

    /**
     * Retrieve a message via artifact binding.
     * @param String $key The key to look for.
     * @return mixed False if there was no suitable message in this binding
     *               String the message if it was found
     *               An exception if something went wrong.
     */

    protected function _receiveMessageFromArtifact($key)
    {
        $artifacts = base64_decode($_REQUEST[self::SAMLArt]);
        $artifacts = unpack(self::ARTIFACT_BINARY_FORMAT, $artifacts);

        switch ($key) {
            case self::SAMLRequest:
                // Trying to get an artifact from an SP, identify ourselves as an idp
                $issuer = $this->_server->getCurrentEntityUrl('idPMetadataService');
                break;
            case self::SAMLResponse:
                // Trying to get an artifact from an IdP, identify ourselves as a sp
                $issuer = $this->_server->getCurrentEntityUrl('sPMetadataService');
                break;
            default:
                throw new Corto_Module_Bindings_Exception("Unknown message type '$key'");
        }

        $artifactResolveMessage = array(
            'samlp:ArtifactResolve' => array(
                '_xmlns:samlp' => 'urn:oasis:names:tc:SAML:2.0:protocol',
                '_xmlns:saml' => 'urn:oasis:names:tc:SAML:2.0:assertion',
                '_ID' => $this->_server->getNewId(),
                '_Version' => '2.0',
                '_IssueInstant' => $this->_server->timeStamp(),
                'saml:Artifact' => array('__v' => $_REQUEST['SAMLArt']),
                'saml:Issuer' => array('__v' => $issuer),
            ),
        );

        if (!isset($artifacts['sourceid'])) {
            throw new Corto_Module_Bindings_Exception("No Source ID found in SAML2 Artifact?!");
        }

        $sourceEntity = $this->_server->getRemoteEntity($artifacts['sourceid']);
        if (!$sourceEntity) {
            throw new Corto_Module_Bindings_Exception("Entity {$artifacts['sourceid']} mentioned in SAML2 Artifact not registered!");
        }
        if (!isset($sourceEntity['ArtifactResolutionServiceLocation'])) {
            throw new Corto_Module_Bindings_Exception("Entity {$artifacts['sourceid']} mentioned in SAML2 Artifact found, but no Artifact Resolution Service is registered");
        }

        $artifactResponse = $this->_soapRequest($sourceEntity['ArtifactResolutionServiceLocation'], $artifactResolveMessage);

        if ($key === self::SAMLRequest) {
            if (isset($artifactResponse['samlp:ArtifactResponse']['samlp:AuthnRequest'])) {
                $message = $artifactResponse['samlp:ArtifactResponse']['samlp:AuthnRequest'];
                $message[Corto_XmlToArray::TAG_NAME_KEY] = 'samlp:AuthnRequest';
            } else {
                return false;
            }
        } else if ($key === self::SAMLResponse) {
            if (isset($artifactResponse['samlp:ArtifactResponse']['samlp:AuthnRequest'])) {
                $message = $artifactResponse['samlp:ArtifactResponse']['samlp:AuthnRequest'];
                $message[Corto_XmlToArray::TAG_NAME_KEY] = 'samlp:Response';
            } else {
                return false;
            }
        }

        $relayState = $_REQUEST['RelayState'];
        $message['__']['RelayState'] = $relayState;

        return $message;
    }

    /**
     * Retrieve a message via http post binding.
     * @param String $key The key to look for.
     * @return mixed False if there was no suitable message in this binding
     *               String the message if it was found
     *               An exception if something went wrong.
     */

    protected function _receiveHTTPPost($key)
    {
        $message = base64_decode($_POST[$key]);
        $messageArray = Corto_XmlToArray::xml2array($message);

        $messageArray['__']['RelayState'] = nvl($_POST, 'RelayState');
        $messageArray['__']['Raw'] = $message;
        $messageArray['__']['paramname'] = $key;
        if (isset($_POST['return'])) {
            $messageArray['__']['Return'] = $_POST['return'];
        }

        return $messageArray;
    }

    /**
     * Receive a message using the JSON-Post binding
     * @param  $key
     * @param  $params
     * @return mixed
     */

    protected function _receiveJSONHTTPPost($key, $params)
    {
        return $this->_receiveJSON($_POST, $key, $params);
    }

    /**
     * Receive a message using the JSON-REdirect binding
     * @param  $key
     * @param  $params
     * @return mixed
     */

    protected function _receiveJSONHTTPRedirect($key, $params)
    {
        return $this->_receiveJSON($_GET, $key, $params);
    }

    /**
     * Receive, decode, inflate and decrypt a JSON encoded message.
     *
     * @param  $request
     * @param  $key
     * @param  $params
     * @return mixed
     *
     */
    protected function _receiveJSON($request, $key, $params)
    {
        $remoteEntity = $this->_server->getRemoteEntity($params['EntityID']);
        $passphrase = nvl3($remoteEntity, 'corto:sharedkey', 0, '__v');
        $messageArray = json_decode(decrypt(gzinflate(base64_decode(nvl($request, $key))), $passphrase), 1);

        $messageArray['__']['RelayState'] = nvl($request, 'RelayState');
        $messageArray['__']['paramname'] = $key;
        $messageArray['__']['Return'] = nvl($request, 'return');
        #$messageArray['__t'] = $key;
        return $messageArray;
    }

    /**
     * Retrieve a message via http redirect binding.
     * @param String $key The key to look for.
     * @return mixed False if there was no suitable message in this binding
     *               String the message if it was found
     *               An exception if something went wrong.
     */
    protected function _receiveHTTPRedirect($key)
    {
        if (!isset($_GET[$key])) {
            return false;
        }

        $message = gzinflate(base64_decode($_GET[$key]));
        $messageArray = Corto_XmlToArray::xml2array($message);

        $relayState = "";
        if (isset($_GET['RelayState'])) {
            $relayState = $_GET['RelayState'];
            $messageArray['__']['RelayState'] = $relayState;
        }

        if (isset($_GET['Signature'])) {
            $messageArray['__']['Signature'] = $_GET['Signature'];
            $messageArray['__']['SigningAlgorithm'] = nvl($_GET, 'SigAlg');
        }

        $messageArray['__']['Raw'] = $message;
        $messageArray['__']['paramname'] = $key;

        return $messageArray;
    }

    /**
     * Take a message out of its SOAP envelope.
     *
     * @param  $key
     * @return
     */
    protected function _receiveSOAP($key)
    {
        $postdata = Corto_XmlToArray::xml2array(file_get_contents("php://input"));
        $req = $postdata['SOAP-ENV:Body'][0]['samlp:' . $key][0];
        $req['__t'] = $key;
        return $req;
    }

    /**
     * Verify that a request has a valid signature (if required), that
     * the issuer is a known entity and that the message is destined for
     * us. Throws an exception if any of these conditions are not met.
     * @param array $request The array with request data
     * @throws Corto_Module_Bindings_VerificationException if any of the
     * verifications fail
     */
    protected function _verifyRequest(array $request)
    {
        $remoteEntity = $this->_verifyKnownIssuer($request);

        if (nvl($remoteEntity, 'AuthnRequestsSigned') ||
                ($this->_server->getCurrentEntitySetting('WantsAuthnRequestsSigned', false))) {
            $this->_verifySignature($request, self::SAMLRequest);
        }

        $this->_verifyMessageDestinedForUs($request);
    }

    /**
     * Verify if a message has an issuer that is known to us. If not, it
     * throws a Corto_Module_Bindings_VerificationException.
     * @param array $message
     * @throws Corto_Module_Bindings_VerificationException
     */
    protected function _verifyKnownIssuer($message)
    {
        $messageIssuer = $message['saml:Issuer']['__v'];
        $remoteEntity = $this->_server->getRemoteEntity($messageIssuer);
        if ($remoteEntity === null) {
            throw new Corto_Module_Bindings_VerificationException("Issuer '{$messageIssuer}' is not a known remote entity? (please add SP/IdP to Remote Entities)");
        }
        return $remoteEntity;
    }

    /**
     * Encrypt an element using a particular public key.
     * @param String $publicKey The public key used for encryption.
     * @param array $element An array representation of an xml fragment
     * @param unknown_type $tag ???
     * @return array The encrypted version of the element.
     */
    protected function _encryptElement($publicKey, $element, $tag = null)
    {
        if ($tag) {
            $element['__t'] = $tag;
        }
        $data = Corto_XmlToArray::array2xml($element);

        $encryptedData = $this->_encrypt($data);

        $publicKey = openssl_pkey_get_public($publicKey);
        openssl_public_encrypt($sessionkey, $encryptedKey, $publicKey, OPENSSL_PKCS1_PADDING);
        openssl_free_key($publicKey);

        $encryptedElement = array(
            'xenc:EncryptedData' => array(
                '_xmlns:xenc' => 'http://www.w3.org/2001/04/xmlenc#',
                '_Type' => 'http://www.w3.org/2001/04/xmlenc#Element',
                'ds:KeyInfo' => array(
                    '_xmlns:ds' => "http://www.w3.org/2000/09/xmldsig#",
                    'xenc:EncryptedKey' => array(
                        '_Id' => $this->_server->getNewId(),
                        'xenc:EncryptionMethod' => array(
                            '_Algorithm' => "http://www.w3.org/2001/04/xmlenc#rsa-1_5"
                        ),
                        'xenc:CipherData' => array(
                            'xenc:CipherValue' => array(
                                '__v' => base64_encode($encryptedKey),
                            ),
                        ),
                    ),
                ),
                'xenc:EncryptionMethod' => array(
                    '_Algorithm' => 'http://www.w3.org/2001/04/xmlenc#aes128-cbc',
                ),
                'xenc:CipherData' => array(
                    'xenc:CipherValue' => array(
                        '__v' => base64_encode($encryptedData),
                    ),
                ),
            ),
        );
        return $encryptedElement;
    }

    /**
     * Decrypt a response message
     * @param array $response The response to decrypt.
     */
    protected function _decryptResponse(array &$response)
    {
        if (isset($response['saml:EncryptedAssertion'])) {
            $encryptedAssertion = $response['saml:EncryptedAssertion'];

            $response['saml:Assertion'] = $this->_decryptElement(
                $this->_getCurrentEntityPrivateKey(),
                $encryptedAssertion
            );
        }
    }

    /**
     * Decrypt an xml fragment.
     * @param String $privateKey The private key to use to decrypt the
     *                           elements.
     * @param array $element Array representation of an xml fragment
     * @param Bool $returnAsXML If true, the method returns an xml string.
     *                          If false (default), it returns an array
     *                          representation of the xml fragment.
     * @return String|Array The decrypted element (as an array or string
     *                      depending on the returnAsXml parameter.
     */
    protected function _decryptElement($privateKey, $element, $returnAsXML = false)
    {
        $encryptedKey = base64_decode($element['xenc:EncryptedData']['ds:KeyInfo']['xenc:EncryptedKey']['xenc:CipherData']['xenc:CipherValue']['__v']);
        $encryptedData = base64_decode($element['xenc:EncryptedData']['xenc:CipherData']['xenc:CipherValue']['__v']);

        openssl_private_decrypt($encryptedKey, $sessionKey, $privateKey, OPENSSL_PKCS1_PADDING);
        openssl_free_key($privateKey);

        $cipher = mcrypt_module_open(MCRYPT_RIJNDAEL_128, '', MCRYPT_MODE_CBC, '');
        $ivSize = mcrypt_enc_get_iv_size($cipher);
        $iv = substr($encryptedData, 0, $ivSize);

        mcrypt_generic_init($cipher, $sessionKey, $iv);

        $decryptedData = mdecrypt_generic($cipher, substr($encryptedData, $ivSize));
        mcrypt_generic_deinit($cipher);
        mcrypt_module_close($cipher);

        if ($returnAsXML) {
            return $decryptedData;
        }
        return Corto_XmlToArray::xml2array($decryptedData);
    }

    /**
     * Verify that a response has a valid signature (if required), that
     * the issuer is a known entity, that the message is destined for
     * us and that the timings in the response is valid.
     * Throws an exception if any of these conditions are not met.
     * @param array $response The array with response data
     * @throws Corto_Module_Bindings_VerificationException if any of the
     * verifications fail
     */
    protected function _verifyResponse(array $response)
    {
        $this->_verifyKnownIssuer($response);

        if ($this->_server->getCurrentEntitySetting('WantsAssertionsSigned', false)) {
            $this->_verifySignature($response, self::SAMLResponse);
        }
        $this->_verifyMessageDestinedForUs($response);
        $this->_verifyTimings($response);
    }

    /**
     * Verify the signature of the message being either a $_GET['Signature'] for
     * the redirect binding or an embedded signature for post and artifact
     * binding.
     *
     * @param  $message
     * @param  $key
     * @return bool
     */
    protected function _verifySignature(array $message, $key)
    {
        if (isset($message['__']['Signature'])) { // We got a Signature in the URL (HTTP Redirect)
            return $this->_verifySignatureMessage($message, $key);
        }

        // Otherwise it's in the message or in the assertion in the message (HTTP Post Response)
        $messageIssuer = $message['saml:Issuer']['__v'];
        $publicKey = $this->_getRemoteEntityPublicKey($messageIssuer);

        $messageVerified = $this->_verifySignatureXMLElement(
            $publicKey,
            $message['__']['Raw'],
            $message
        );

        if (!isset($message['saml:Assertion'])) {
            return $messageVerified;
        }

        $assertionVerified = $this->_verifySignatureXMLElement(
            $publicKey,
            $message['__']['Raw'],
            $message['saml:Assertion']
        );
        return ($messageVerified || $assertionVerified);
    }

    /**
     * Verify the $_GET['Signature'] in a message
     *
     * @throws Corto_Module_Bindings_VerificationException
     * @param  $message
     * @param  $key
     * @return
     */
    protected function _verifySignatureMessage($message, $key)
    {
        $rawGet = $this->_server->getRawGet();

        $queryString = "$key=" . $rawGet[$key];
        if (isset($rawGet['RelayState'])) {
            $queryString .= '&RelayState=' . $rawGet['RelayState'];
        }
        $queryString .= '&SigAlg=' . $rawGet['SigAlg'];

        $messageIssuer = $message['saml:Issuer']['__v'];
        $publicKey = $this->_getRemoteEntityPublicKey($messageIssuer);

        $verified = openssl_verify(
            $queryString,
            base64_decode($message['Signature']),
            $publicKey
        );

        if (!$verified) {
            throw new Corto_Module_Bindings_VerificationException("Invalid signature");
        }

        return ($verified === 1);
    }

    /**
     * Verify the embedded signature in an element
     *
     * @param  $publicKey
     * @param  $xml
     * @param  $element
     * @return bool
     *
     */
    protected function _verifySignatureXMLElement($publicKey, $xml, $element)
    {
        $signatureValue = base64_decode($element['ds:Signature']['ds:SignatureValue']['__v']);
        $digestValue = base64_decode($element['ds:Signature']['ds:SignedInfo']['ds:Reference']['ds:DigestValue']['__v']);

        $document = DOMDocument::loadXML($xml);
        $xp = new DomXPath($document);
        $xp->registerNamespace('ds', 'http://www.w3.org/2000/09/xmldsig#');

        $id = $element['_ID'];
        $signedElement = $xp->query("//*[@ID = '$id']")->item(0);
        $signature = $xp->query(".//ds:Signature", $signedElement)->item(0);
        $signedInfo = $xp->query(".//ds:SignedInfo", $signature)->item(0)->C14N(true, false);
        $signature->parentNode->removeChild($signature);
        $canonicalXml = $signedElement->C14N(true, false);

        $digestMatches = (sha1($canonicalXml, TRUE) == $digestValue);
        if (!$digestMatches) {
            return false;
        }
        return (openssl_verify($signedInfo, $signatureValue, $publicKey) == 1);
    }

    /**
     * Verify that we are the destination for a message
     *
     * @throws Corto_Module_Bindings_VerificationException
     * @param  $message
     * @return void
     */
    protected function _verifyMessageDestinedForUs(array $message)
    {
        if ($destinationId = nvl($message, '_Destination')) { // Destination is optional
            if ($this->_server->selfDestination() != $destinationId) {
                throw new Corto_Module_Bindings_VerificationException("Destination: '$destinationId' is not here; message not destined for us");
            }
        }
    }

    /**
     * Verify that the timings in a message is ok
     *
     * @throws Corto_Module_Bindings_Exception
     * @param  $message
     * @return bool
     */
    protected function _verifyTimings(array $message)
    {
        // just use string cmp all times in ISO like format without timezone (but everybody appends a Z anyways ...)
        $skew = $this->_server->getCurrentEntitySetting('max_age_seconds', 300);
        $aShortWhileAgo = $this->_server->timeStamp(-$skew);
        $inAShortWhile = $this->_server->timeStamp($skew);
        $issues = array();

        // Check SAMLResponse SubjectConfirmation timings

        if (isset($message['saml:Assertion']['saml:Subject']['saml:SubjectConfirmation']['saml:SubjectConfirmationData']['_NotBefore'])) {
            if ($inAShortWhile < $message['saml:Assertion']['saml:Subject']['saml:SubjectConfirmation']['saml:SubjectConfirmationData']['_NotBefore']) {
                $issues[] = 'SubjectConfirmation not valid yet';
            }
        }

        if (isset($message['saml:Assertion']['saml:Subject']['saml:SubjectConfirmation']['saml:SubjectConfirmationData']['_NotOnOrAfter'])) {
            if ($aShortWhileAgo > $message['saml:Assertion']['saml:Subject']['saml:SubjectConfirmation']['saml:SubjectConfirmationData']['_NotOnOrAfter']) {
                $issues[] = 'SubjectConfirmation too old';
            }
        }

        // Check SAMLResponse Conditions timings

        if (isset($message['saml:Assertion']['saml:Conditions']['_NotBefore'])) {
            if ($inAShortWhile < $message['saml:Assertion']['saml:Conditions']['_NotBefore']) {
                $issues[] = 'Assertion Conditions not valid yet';
            }
        }

        if (isset($message['saml:Assertion']['saml:Conditions']['_NotOnOrAfter'])) {
            if ($aShortWhileAgo > $message['saml:Assertion']['saml:Conditions']['_NotOnOrAfter']) {
                $issues[] = 'Assertions Condition too old';
            }
        }

        // Check SAMLResponse AuthnStatement timing

        if (isset($message['saml:Assertion']['saml:AuthnStatement']['_SessionNotOnOrAfter'])) {
            if ($aShortWhileAgo > $message['saml:Assertion']['saml:AuthnStatement']['_SessionNotOnOrAfter']) {
                $issues[] = 'AuthnStatement Session too old';
            }
        }

        if (!empty($issues)) {
            $message = 'Problems detected with timings! Please check if your server has the correct time set.';
            $message .= ' Issues: ' . implode(PHP_EOL, $issues);
            throw new Corto_Module_Bindings_Exception($message);
        }
        return true;
    }

    /**
     * Send a soap request and return the resulting response without
     * the enclosing soap envelope.
     *
     * @param  $soapServiceUrl
     * @param  $body
     * @return
     */
    protected function _soapRequest($soapServiceUrl, $body)
    {
        $soapEnvelope = array(
            '__t' => 'SOAP-ENV:Envelope',
            '_xmlns:SOAP-ENV' => "http://schemas.xmlsoap.org/soap/envelope/",
            'SOAP-ENV:Body' => array($body['__t'] => $body),
        );

        $curlOptions = array(
            CURLOPT_URL => $soapServiceUrl,
            CURLOPT_HTTPHEADER => array('SOAPAction: ""'),
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_SSL_VERIFYPEER => FALSE,
            CURLOPT_POSTFIELDS => Corto_XmlToArray::array2xml($soapEnvelope),
            CURLOPT_HEADER => 0,
        );

        $curlHandler = curl_init();
        curl_setopt_array($curlHandler, $curlOptions);
        $curlResult = curl_exec($curlHandler);

        $soapResponse = Corto_XmlToArray::xml2array($curlResult);

        return $soapResponse['SOAP-ENV:Body'];
    }

    /**
     * Send a response as a soap message by embedding it in a soap
     * envelope.
     * @param  $body
     * @return void
     */
    protected function _soapResponse(array $body)
    {
        $soapResponse = array(
            '__t' => 'SOAP-ENV:Envelope',
            '_xmlns:SOAP-ENV' => "http://schemas.xmlsoap.org/soap/envelope/",
            'SOAP-ENV:Body' => array($body['__t'] => $body),
        );

        $xml = Corto_XmlToArray::array2xml($soapResponse);

        $this->_server->sendHeader('Content-Type', 'application/xml');
        $this->_server->sendOutput($xml);
    }

    /**
     * Send a message using the protocolbinding stated in the corto
     * private ('__') part of the message.
     *
     * @throws Corto_Module_Bindings_Exception
     * @param  $message
     * @param  $remoteEntity
     * @return void
     */
    public function send(array $message, array $remoteEntity)
    {
        $bindingUrn = $message['__']['ProtocolBinding'];

        if (!isset($this->_bindings[$bindingUrn])) {
            throw new Corto_Module_Bindings_Exception('Unknown binding: ' . $bindingUrn);
        }
        $function = '_send' . $this->_bindings[$bindingUrn];

        $this->$function($message, $remoteEntity);
    }

    /**
     * Respond using the protocolbinding stated in the corto private
     * )'__') part of the message.
     *
     * @throws Corto_Module_Bindings_Exception
     * @param  $message
     * @param  $remoteEntity
     * @return void
     *
     */
    public function respond(array $message, array $remoteEntity)
    {
        $bindingUrn = $message['__']['ProtocolBinding'];

        if (!isset($this->_bindings[$bindingUrn])) {
            throw new Corto_Module_Bindings_Exception('Unknown binding: ' . $bindingUrn);
        }
        $binding = $this->_bindings[$bindingUrn];
        if ($binding == 'SOAP') {
            $function = '_respond' . $binding;
        } else {
            $function = '_send' . $binding;
        }
        $this->$function($message, $remoteEntity);
    }

    /**
     * Send a message using the HTTP-Post binding.
     *
     * @param  $message
     * @param  $remoteEntity
     * @return void
     */
    protected function _sendHTTPPost($message, $remoteEntity)
    {
        $name = $message['__']['paramname'];
        if ($name == 'SAMLRequest' && ($remoteEntity['WantsAuthnRequestsSigned'] || $this->_server->getCurrentEntitySetting('AuthnRequestsSigned'))) {
            $this->_server->getSessionLog()->debug("HTTP-Redirect: (Re-)Signing");
            $message = $this->_sign(
                $this->_getCurrentEntityPrivateKey(),
                $message
            );
        } else if ($name == 'SAMLResponse' && isset($remoteEntity['WantsAssertionsSigned']) && $remoteEntity['WantsAssertionsSigned']) {
            $this->_server->getSessionLog()->debug("HTTP-Redirect: (Re-)Signing Assertion");

            $message['saml:Assertion']['__t'] = 'saml:Assertion';
            $message['saml:Assertion']['_xmlns:saml'] = "urn:oasis:names:tc:SAML:2.0:assertion";
            unset($message['saml:Assertion']['ds:Signature']);
            ksort($message['saml:Assertion']);

            $message['saml:Assertion'] = $this->_sign(
                $this->_getCurrentEntityPrivateKey(),
                $message['saml:Assertion']
            );
            ksort($message['saml:Assertion']);
            #$enc = docrypt(certs::$server_crt, $message['saml:Assertion'], 'saml:EncryptedAssertion');
        } else if ($name == 'SAMLResponse' && isset($remoteEntity['WantsResponsesSigned']) && $remoteEntity['WantsResponsesSigned']) {
            $this->_server->getSessionLog()->debug("HTTP-Redirect: (Re-)Signing");
            $message = $this->_sign(
                $this->_getCurrentEntityPrivateKey(),
                $message
            );
        }

        $encodedMessage = Corto_XmlToArray::array2xml($message);
        if ($this->_server->getCurrentEntitySetting('debug')) {
            $dom = new DOMDocument();
            $dom->loadXML($encodedMessage);
            if (!$dom->schemaValidate('http://docs.oasis-open.org/security/saml/v2.0/saml-schema-protocol-2.0.xsd')) {
                //echo '<pre>'.htmlentities(Corto_XmlToArray::formatXml($encodedMessage)).'</pre>';
                //throw new Exception('Message XML doesnt validate against XSD at Oasis-open.org?!');
            } else if ($name == 'SAMLResponse' && isset($remoteEntity['WantsResponsesSigned']) && $remoteEntity['WantsResponsesSigned']) {
                $this->_server->getSessionLog()->debug("HTTP-Redirect: (Re-)Signing");
                $message = $this->_sign(
                    $this->_getCurrentEntityPrivateKey(),
                    $message
                );
            }

            $encodedMessage = Corto_XmlToArray::array2xml($message);

            $schemaUrl = 'http://docs.oasis-open.org/security/saml/v2.0/saml-schema-protocol-2.0.xsd';
            if ($this->_server->getCurrentEntitySetting('debug') && ini_get('allow_url_fopen') && file_exists($schemaUrl)) {
                $dom = new DOMDocument();
                $dom->loadXML($encodedMessage);
                if (!$dom->schemaValidate($schemaUrl)) {
                    //echo '<pre>'.htmlentities(Corto_XmlToArray::formatXml($encodedMessage)).'</pre>';
                    //throw new Exception('Message XML doesnt validate against XSD at Oasis-open.org?!');
                }
            }
        }


        $extra = isset($message['__']['RelayState']) ? '<input type="hidden" name="RelayState" value="' . htmlspecialchars($message['__']['RelayState']) . '">' : '';
        $extra .= isset($message['__']['return']) ? '<input type="hidden" name="return" value="' . htmlspecialchars($message['__']['return']) . '">' : '';
        $encodedMessage = htmlspecialchars(base64_encode($encodedMessage));

        $action = $message['_Destination'] . (isset($message['_Recipient']) ? $message['_Recipient'] : '');
        $this->_server->getSessionLog()->debug("HTTP-Post: Sending Message: " . var_export($message, true));
        $output = $this->_server->renderTemplate(
            'form',
            array(
                'action' => $action,
                'message' => $encodedMessage,
                'xtra' => $extra,
                'name' => $name,
                #                'trace' => $this->_server->getConfig('debug', false) ? htmlentities(Corto_XmlToArray::formatXml(Corto_XmlToArray::array2xml($message))) : '',
                #                'trace' => htmlentities(Corto_XmlToArray::formatXml(Corto_XmlToArray::array2xml($message))),
                #'trace' => htmlentities(print_r($message, 1)),
                'trace' => false,
            ));
        $this->_server->sendOutput($output);
    }

    /**
     * Send a message using the HTTP-Redirect binding.
     * @param  $message
     * @param  $remoteEntity
     * @return void
     */
    protected function _sendHTTPRedirect(array $message, $remoteEntity)
    {
        $messageType = $message['__']['paramname'];

        // Determine if we should sign the message
        $wantRequestsSigned = (nvl($remoteEntity, 'WantsAuthnRequestsSigned') ||
                $this->_server->getCurrentEntitySetting('AuthnRequestsSigned'));
        $mustSign = ($messageType === self::SAMLRequest && $wantRequestsSigned);
        if ($mustSign) {
            $this->_server->getSessionLog()->debug("HTTP-Redirect: Removing signature");
            unset($message['ds:Signature']);
        }

        // Encode the message in destination format

        $encodedMessage = Corto_XmlToArray::array2xml($message);


        // Encode the message for transfer
        $encodedMessage = urlencode(base64_encode(gzdeflate($encodedMessage)));

        // Build the query string
        $queryString = "$messageType=" . $encodedMessage
                . (nvl($message['__'], 'RelayState') ? '&RelayState=' . urlencode($message['__']['RelayState']) : "");

        // Sign the message
        if ($mustSign) {
            $this->_server->getSessionLog()->debug("HTTP-Redirect: (Re-)Signing");
            $queryString .= '&SigAlg=' . urlencode($this->_server->getCurrentEntitySetting('SigningAlgorithm'));

            $key = $this->_getCurrentEntityPrivateKey();

            $signature = "";
            openssl_sign($queryString, $signature, $key);
            openssl_free_key($key);

            $queryString .= '&Signature=' . urlencode(base64_encode($signature));
        }

        // Build the full URL
        $location = nvl($message, '_Destination') . nvl($message, '_Recipient'); # shib remember ...
        $location .= "?" . $queryString;

        // Redirect
        $this->_server->redirect($location, $message);
    }

    /**
     * Send a message using the soap binding
     * @param  $message
     * @param  $remoteEntity
     * @return
     */
    protected function _sendSOAP(array $message, array $remoteEntity)
    {
        $body = $message; #Corto_XmlToArray::array2xml($message);
        $soapServiceUrl = $message['_Destination'];
        return $this->_soapRequest($soapServiceUrl, $body);
    }

    /**
     * Send a response using the soap binding.
     * @param  $message
     * @param  $remoteEntity
     * @return void
     */
    protected function _respondSOAP(array $message, array $remoteEntity)
    {
        $body = $message; #Corto_XmlToArray::array2xml($message);
        $soapServiceUrl = $message['_Destination'];
        return $this->_soapResponse($body);
    }

    /**
     * Send a message using the JSON-Redirect binding.
     * @param  $message
     * @param  $remoteEntity
     * @return void
     */
    protected function _sendJSONHTTPRedirect(array $message, $remoteEntity)
    {
        $messageType = $message['__']['paramname'];

        $idporsp = $messageType === self::SAMLRequest ? 'IDP' : 'SP';
        $encodedMessage = encrypt(json_encode($message), $remoteEntity['corto:sharedkey'][0]['__v']);

        // Encode the message for transfer
        $encodedMessage = urlencode(base64_encode(gzdeflate($encodedMessage)));

        // Build the query string
        $queryString = "$messageType=" . $encodedMessage
                . (nvl($message['__'], 'RelayState') ? '&RelayState=' . urlencode($message['__']['RelayState']) : "");

        // Build the full URL
        $location = $message['_Destination'] . "?" . $queryString;
        ;

        // Redirect
        $this->_server->redirect($location, $message);
    }

    /**
     * Send a message using the internal binding.
     * @param  $message
     * @param  $remoteEntity
     * @return void
     */
    public function _sendInternal($message, $remoteEntity)
    {
        // Store the message
        $_REQUEST[$message['__']['paramname']] = $message;
        $this->_server->serveRequest($message['_Destination']);

        /*

          $parameters = $this->_server->getParametersFromUrl($destinationLocation);
          $this->_server->setCurrentEntity($parameters['EntityID'], $parameters['Federation']);

          $this->_server->getSessionLog()->debug("Using internal binding for destination: $destinationLocation, resulting in parameters: " . var_export($parameters, true));

          $serviceName = $parameters['ServiceName'];

          $this->_server->getSessionLog()->debug("Calling service '$serviceName'");
          $this->_server->getServicesModule()->$serviceName();
          $this->_server->getSessionLog()->debug("Done calling service '$serviceName'");

          */
    }

    /**
     * Get the private key for the current entity.
     * @throws Corto_Module_Bindings_Exception
     * @return resource
     */
    protected function _getCurrentEntityPrivateKey()
    {
        $certificates = $this->_server->getCurrentEntitySetting('certificates', array());
        if (!isset($certificates['private'])) {
            throw new Corto_Module_Bindings_Exception('Current entity has no private key, unable to sign message! Please set ["certificates"]["private"]!');
        }
        $key = openssl_pkey_get_private($certificates['private']);
        if ($key === false) {
            throw new Corto_Module_Bindings_Exception("Current entity ['certificates']['private'] value is NOT a valid PEM formatted SSL private key?!? Value: " . $certificates['private']);
        }
        return $key;
    }

    /**
     * Get the public key (certificate) for a remote entity.
     *
     * @throws Corto_Module_Bindings_Exception|Corto_Module_Bindings_VerificationException
     * @param  $entityId
     * @return resource
     */
    protected function _getRemoteEntityPublicKey($entityId)
    {
        $remoteEntity = $this->_server->getRemoteEntity($entityId);

        if (!isset($remoteEntity['certificates']['public'])) {
            throw new Corto_Module_Bindings_VerificationException("No public key known for $entityId");
        }

        $publicKey = openssl_pkey_get_public($remoteEntity['certificates']['public']);
        if ($publicKey === false) {
            throw new Corto_Module_Bindings_Exception("Public key for $entityId is NOT a valid PEM SSL public key?!?! Value: " . $remoteEntity['certificates']['public']);
        }

        return $publicKey;
    }

    /**
     * Sign an element using $key
     * @param  $key
     * @param  $element
     * @return
     */
    protected function _sign($key, $element)
    {
        $signature = array(
            '__t' => 'ds:Signature',
            '_xmlns:ds' => 'http://www.w3.org/2000/09/xmldsig#',
            'ds:SignedInfo' => array(
                '__t' => 'ds:SignedInfo',
                '_xmlns:ds' => 'http://www.w3.org/2000/09/xmldsig#',
                'ds:CanonicalizationMethod' => array(
                    '_Algorithm' => 'http://www.w3.org/2001/10/xml-exc-c14n#',
                ),
                'ds:SignatureMethod' => array(
                    '_Algorithm' => 'http://www.w3.org/2000/09/xmldsig#rsa-sha1',
                ),
                'ds:Reference' => array(
                    '_URI' => '__placeholder__',
                    'ds:Transforms' => array(
                        'ds:Transform' => array(
                            array(
                                '_Algorithm' => 'http://www.w3.org/2000/09/xmldsig#enveloped-signature',
                            ),
                            array(
                                '_Algorithm' => 'http://www.w3.org/2001/10/xml-exc-c14n#',
                            ),
                        ),
                    ),
                    'ds:DigestMethod' => array(
                        '_Algorithm' => 'http://www.w3.org/2000/09/xmldsig#sha1',
                    ),
                    'ds:DigestValue' => array(
                        '__v' => '__placeholder__',
                    ),
                ),
            ),
        );

        $canonicalXml = DOMDocument::loadXML(Corto_XmlToArray::array2xml($element))->firstChild->C14N(true, false);

        $signature['ds:SignedInfo']['ds:Reference']['ds:DigestValue']['__v'] = base64_encode(sha1($canonicalXml, TRUE));
        $signature['ds:SignedInfo']['ds:Reference']['_URI'] = "#" . $element['_ID'];

        $canonicalXml2 = DOMDocument::loadXML(Corto_XmlToArray::array2xml($signature['ds:SignedInfo']))->firstChild->C14N(true, false);

        openssl_sign($canonicalXml2, $signatureValue, $key);

        openssl_free_key($key);
        $signature['ds:SignatureValue']['__v'] = base64_encode($signatureValue);
        foreach ($element as $tag => $item) {
            if ($tag == 'ds:Signature') {
                continue;
            }

            $newElement[$tag] = $item;

            if ($tag == 'saml:Issuer') {
                $newElement['ds:Signature'] = $signature;
            }
        }

        return $newElement;
    }

}