<?php
/**
 * The Metadata.obsolete class transforms SAML standard metadata (in XML, php or json)
 * to a format suitable for use by Corto.
 *
 * It should be used when new metadata is available.
 *
 * You add metadata by calling prepareMetadata.
 *
 * The metadata file should be writable by the caller and readable by the corto webserver.
 *
 */
class Corto_Module_Metadata {

    protected static $descriptors = array('SP', 'IDP');
    protected static $services = array('AssertionConsumerService', 'ArtifactResolutionService', 'SingleSignOnService', 'SingleLogoutService');
    protected static $signings = array('WantAuthnRequestsSigned', 'WantAssertionsSigned', 'AuthnRequestsSigned', 'WantResponsesSigned');

    public function prepareMetadata($metadatasources, $metadatafile) {
        $md = array();
        foreach ($metadatasources as $source) {
            $md = self::merge($md, self::optimizeMetaData(self::retrieveMetadata($source), (array) nvl($md, '_COMMON_')));
        }
        unset($md['_COMMON_']);
        $export = array('md' => $md, 'lookuptable' => $this->prepareLookuptables($md));
        file_put_contents($metadatafile . '.tmp', "return " . var_export($export, true) . ";");
        @rename($metadatafile . '.tmp', $metadatafile);

    }

    public function getMetadata($metadatafile) {
        return include $metadatafile;
    }

    protected static function retrieveMetadata($source) {
        if (is_array($source)) {
            return $source;
        }
        $type = 'xml';
        if (preg_match("/^(xml|php|json|array):(.*)/", $source, $dollar)) {
            $type = $dollar[1];
            $source = $dollar[2];
        }

        switch ($type) {
            case 'xml':
                return Corto_XmlToArray::xml2array(file_get_contents($source), true);
                break;
            case 'php':
                // the included file must return an array
                return include $source;
                break;
            case 'json':
                return json_decode(file_get_contents($source), 1);
                break;
        }
    }

    protected static function optimizeMetaData($rawmeta, $commonmd = array()) {
        // @note remember not to set keys for things that might be overidden by merged md
        // ie. set ['saveSLOInfo'] to true, but do not set the ['saveSLOInfo'] at all
        // when false as it WILL overwrite the true !!!
        $meta = array();
        if ($entitymd = nvl($rawmeta, 'md:EntityDescriptor')) {
            $rawmeta['md:EntitiesDescriptor'] = array(
                array(
                    'md:EntityDescriptor' => $entitymd,
                ),
            );
            unset($rawmeta['md:EntityDescriptor']);
        }

        foreach ((array) $rawmeta['md:EntitiesDescriptor'] as $entitiesDescriptor) {
            $entitiescommon = array();
            if (isset($entitiesDescriptor['md:Extensions']['mdattr:EntityAttributes']['saml:Attribute'])) {
                foreach ((array) $entitiesDescriptor['md:Extensions']['mdattr:EntityAttributes']['saml:Attribute'] as $attribute) {
                    foreach ((array) $attribute['saml:AttributeValue'] as $attributeValue) {
                        $entitiescommon[$attribute['_Name']][] = $attributeValue;
                    }
                }
            }

            $entitiescommon = self::merge($commonmd, $entitiescommon);

            foreach ((array) $entitiesDescriptor['md:EntityDescriptor'] as $entityDescriptor) {
                if (empty($entityDescriptor['_entityID'])) $entityDescriptor['_entityID'] = '_COMMON_';
                $cortoEntityDescriptor = array();
                $cortoEntityDescriptor['entityID'] = $entityDescriptor['_entityID'];
                $cortoOriginals[$entityDescriptor['_entityID']] = $entityDescriptor;

                foreach ((array) nvl3($entityDescriptor, 'md:Extensions', 'mdattr:EntityAttributes', 'saml:Attribute') as $attribute) {
                    foreach ((array) $attribute['saml:AttributeValue'] as $attributeValue) {
                        $cortoEntityDescriptor[$attribute['_Name']][] = $attributeValue;
                    }
                }

                foreach ((array) nvl($entityDescriptor, 'md:IDPSSODescriptor') as $idpsso) {
                    foreach (array('SingleSignOnService', 'SingleLogoutService') as $service) {
                        foreach ((array) nvl($idpsso, 'md:' . $service) as $sso) {
                            $cortoEntityDescriptor['IDP'][$service][] = array(
                                'Location' => $sso['_Location'],
                                'Binding' => $sso['_Binding'],
                            );
                        }
                    }

                    // metadata overrides auto setting
                    if (empty($cortoEntityDescriptor['IDP']['saveSLOInfo'])
                            && $saveSLOInfo = (boolean) nvl2($cortoEntityDescriptor, 'IDP', 'SingleLogoutService')) {
                        $cortoEntityDescriptor['IDP']['saveSLOInfo'] = $saveSLOInfo;
                    }
                }


                foreach ((array) nvl($entityDescriptor, 'md:SPSSODescriptor') as $spsso) {
                    foreach (array('AssertionConsumerService', 'SingleLogoutService') as $service) {
                        foreach ((array) nvl($spsso, 'md:' . $service) as $acs) {
                            $cortoEntityDescriptor['SP'][$service][$acs['_index']] = array(
                                'Location' => $acs['_Location'],
                                'Binding' => $acs['_Binding'],
                                'isDefault' => empty($acs['_isDefault']) ? null : $acs['_isDefault'],
                            );
                        }
                    }

                    // metadata overrides auto setting
                    if (empty($cortoEntityDescriptor['SP']['saveSLOInfo'])
                            && $saveSLOInfo = (boolean) nvl2($cortoEntityDescriptor, 'SP', 'SingleLogoutService')) {
                        $cortoEntityDescriptor['SP']['saveSLOInfo'] = $saveSLOInfo;
                    }
                }

                // this is the default resolution algorithm from Meta 2.2.3
                if (isset($cortoEntityDescriptor['SP']['AssertionConsumerService'])) {
                    $acslist = &$cortoEntityDescriptor['SP']['AssertionConsumerService'];
                    ksort($acslist);
                    $default = null;
                    foreach ((array) $acslist as $index => $acs) {
                        if ($acs['isDefault']) {
                            $default = $index;
                        }
                    }
                    $cortoEntityDescriptor['SP']['AssertionConsumerService']['default']
                            = $default ? $default : min(array_keys($acslist));
                }

                foreach (self::$descriptors as $descriptor) {
                    foreach ((array) nvl($entityDescriptor, 'md:' . $descriptor . 'SSODescriptor') as $idporsp) {
                        foreach (self::$signings as $signing) {
                            if (isset($idporsp['_' . $signing])) {
                                $cortoEntityDescriptor[$descriptor][$signing] =
                                        ($idporsp['_' . $signing] == 'true' || $idporsp['_' . $signing] == '1');
                            }
                        }

                        foreach ((array) nvl3($idporsp, 'md:Extensions', 'mdattr:EntityAttributes', 'saml:Attribute') as $attribute) {
                            foreach ((array) $attribute['saml:AttributeValue'] as $attributeValue) {
                                $cortoEntityDescriptor[$descriptor][$attribute['_Name']][] = $attributeValue;
                            }
                        }

                        foreach ((array) nvl($idporsp, 'md:KeyDescriptor') as $keyDescriptor) {
                            $use = nvl($keyDescriptor, '_use', 'signing');
                            $cortoEntityDescriptor[$descriptor][$use]['X509Certificate'] =
                                    $keyDescriptor['ds:KeyInfo']['ds:X509Data']['ds:X509Certificate']['__v'];
                            /*                               $cortoEntityDescriptor[$descriptor][$keyDescriptor['_use']]['KeyName'] =
                            $keyDescriptor['ds:KeyInfo']['ds:X509Data']['ds:KeyName']['__v'];
                            */
                        }

                        foreach ((array) nvl($cortoEntityDescriptor[$descriptor], 'corto:privatekey') as $privatekey) {
                            $cortoEntityDescriptor[$descriptor][$privatekey['_use']]['X509Privatekey'] = $privatekey['__v'];
                        }
                        unset($cortoEntityDescriptor[$descriptor]['corto:privatekey']);

                        if ($attributes = nvl($cortoEntityDescriptor[$descriptor], 'corto:IDPList')) {
                            $idplist = array();
                            foreach ($attributes as $values) {
                                $idplist[] = $values['__v'];
                            }
                            $cortoEntityDescriptor[$descriptor]['corto:IDPList'] = $idplist;
                        }

                        #$cortoEntityDescriptor[$descriptor] = self::merge(nvl($common, $descriptor), nvl($cortoEntityDescriptor, $descriptor));
                        #unset($common[$descriptor]);
                    }
                }
                $meta[$entityDescriptor['_entityID']] = self::merge($entitiescommon, $cortoEntityDescriptor);
            }
        }
        return $meta;
    }

    protected function prepareLookuptables($metadata) {
        $url2meta = array();

        foreach ($metadata as $id => $entity) {
            $sourceid = sha1($id);
            foreach (self::$descriptors as $descriptor) {
                foreach (self::$services as $service) {
                    if (isset($entity[$descriptor][$service])) {
                        foreach ((array) $entity[$descriptor][$service] as $index => $theservice) {
                            if (!is_int($index)) continue;
# multiple bindings per url are NOT allowed! flag it here ...
# should $theservice['_Location'] be hashed ?
                            $url2meta[$theservice['Location']] = array(
                                'EntityID' => $id,
                                'Service' => $service,
                                'Binding' => $theservice['Binding'],
                            );
                            if ($theservice['Binding'] == 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Artifact') {
                                $url2meta[$sourceid][$index] = $theservice['Location'];
                            }
                        }
                    }
                }
                /**
                 * This is the entry for the metadataservice.
                 * The entityID is the url so ProxyServer::getParametersFromUrl
                 * fills in the rest
                 *
                 */
                $url2meta[$id] = true;

            }
        }
        return $url2meta;
    }

    private function merge($a, $b) {
        foreach ((array) $b as $k => $v) {
            if (is_array($v)) {
                if (!isset($a[$k])) {
                    $a[$k] = $v;
                } else {
                    $a[$k] = self::merge($a[$k], $v);
                }
            } else {
                $a[$k] = $v;
            }
        }
        return $a;
    }


}
