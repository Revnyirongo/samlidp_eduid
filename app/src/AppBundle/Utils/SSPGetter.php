<?php

namespace AppBundle\Utils;

use AppBundle\Entity\IdPAudit;
use AppBundle\Entity\IdP;
use Doctrine\ORM\EntityNotFoundException;
use Symfony\Component\Translation\DataCollectorTranslator;

class SSPGetter
{
    protected $em;
    private $translator;

    public function __construct(\Doctrine\ORM\EntityManager $em, $database_host, $database_name, $database_user, $database_password, $database_driver, $database_port, $samlidp_hostname)
    {
        $this->em = $em;
        $this->database_host = $database_host;
        $this->database_name = $database_name;
        $this->database_user = $database_user;
        $this->database_port = $database_port;
        $this->database_password = $database_password;
        $this->database_type = preg_replace('/pdo_/', '', $database_driver);
        $this->samlidp_hostname = $samlidp_hostname;
    }

    public function getSamlidpHostname()
    {
        return $this->samlidp_hostname;
    }

    public function getLoginPageData($host)
    {
        $idp = $this->em->getRepository('AppBundle:IdP')->findOneByHostname(str_replace('.' . $this->samlidp_hostname, '', $host));
        if ($idp) {
            $result = array();
            foreach ($idp->getOrganizationElements() as $orgElem) {
                if ($orgElem->getType() == 'Name') {
                    $result['OrganizationName'] = $orgElem->getValue();
                }
            }
            if (!empty($idp->getLogo())) {
                $result['Logo'] = array(
                        'url' => 'https://'.$this->samlidp_hostname.'/images/idp_logo/'.$idp->getLogo(),
                        'width' => 200,
                        'height' => 200,
                        );
            }
            foreach ($idp->getUsers() as $contact) {
                $result['contact'] = array(
                    'name' => $contact->getGivenName().' '.$contact->getSn(),
                    'email' => $contact->getEmail(),
                );
            }
            $result['status'] = $idp->getStatus();
            $result['hostname'] = $idp->getHostname();

            return $result;
        }
    }

    public function getSaml20spremoteForAnIdp($host)
    {
        // Itt állítjuk össze az adott IdP-hez tartozó saml20-sp-remote.php listát.
        $idp = $this->em->getRepository('AppBundle:IdP')->findOneByHostname(str_replace('.' . $this->samlidp_hostname, '', $host));
        if (!$idp) {
            throw new EntityNotFoundException('No IdP found in the database.');
        }
        $metadata = array();

        // Itt szedjük ki a föderációs SP-ket, melyeket az IdP szeret
        $federations = $idp->getFederations();
        foreach ($federations as $federation) {
            $entities = $federation->getEntities();
            foreach ($entities as $entity) {
                $entityData = unserialize(stream_get_contents($entity->getEntitydata()));
                $metadata[$entity->getEntityid()] = $entityData;
            }
        }

        // Itt szedjük ki a föderáción kívüli SP-ket, melyeket az IdP szeret
        $entities = $idp->getEntities();
        foreach ($entities as $entity) {
            if (!isset($metadata[$entity->getEntityid()])) {
                $entityData = unserialize(stream_get_contents($entity->getEntitydata()));
                $metadata[$entity->getEntityid()] = $entityData;
            }
        }
        return $metadata;
    }

    public function getIdps($host)
    {
        $idps = $this->em->getRepository('AppBundle:IdP')->findByHostname(str_replace('.' . $this->samlidp_hostname, '', $host));
        if (count($idps) == 0) {
            // szándékos fallback az összes IdP listázására, ha valahol nem direktben hívják meg
            $idps = $this->em->getRepository('AppBundle:IdP')->findAll();
        }
        $result = array();
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $incomingHost = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
        $portSuffix = '';
        $port = null;

        if (!empty($_SERVER['HTTP_X_FORWARDED_PORT']) && ctype_digit((string) $_SERVER['HTTP_X_FORWARDED_PORT'])) {
            $port = (string) $_SERVER['HTTP_X_FORWARDED_PORT'];
        } elseif (strpos($incomingHost, ':') !== false) {
            $parts = explode(':', $incomingHost);
            $portCandidate = array_pop($parts);
            if (ctype_digit($portCandidate)) {
                $port = $portCandidate;
            }
        } elseif (!empty($_SERVER['SERVER_PORT']) && ctype_digit((string) $_SERVER['SERVER_PORT'])) {
            $port = (string) $_SERVER['SERVER_PORT'];
        }

        if ($port !== null) {
            $isHttps = ($scheme === 'https');
            $isDefaultPort = ($isHttps && $port === '443') || (!$isHttps && $port === '80');
            if (!$isDefaultPort) {
                $portSuffix = ':' . $port;
            }
        }

        $hostWithoutPort = strtolower(preg_replace('/:\\d+$/', '', (string) $incomingHost));

        foreach ($idps as $idp) {
            if (strlen($idp->getInstituteName())>1) {
                $certDescriptor = $this->resolveCertificateDescriptor($idp, $hostWithoutPort);
                if ($certDescriptor === null) {
                    // Skip IdP entries without a valid certificate/key pair
                    continue;
                }

                $result[$idp->getEntityId($this->samlidp_hostname)] = array(
                    'host' => $idp->getHostname().'.'.$this->samlidp_hostname,
                    'privatekey' => $certDescriptor['relativeKey'],
                    'certificate' => $certDescriptor['relativeCert'],
                    'scope' => $idp->getScopes(),
                    'certData' => $certDescriptor['certData'],
                    'auth' => 'as-'.$idp->getHostname(),
                    'attributes.NameFormat' => 'urn:oasis:names:tc:SAML:2.0:attrname-format:uri',
                    'userid.attribute' => 'username',
                    'attributeencodings' => array(
                      'urn:oid:1.3.6.1.4.1.5923.1.1.1.10' => 'raw',
                    ),
                    'sign.logout' => true,
                    'redirect.sign' => true,
                    'assertion.encryption' => true,
                    'EntityAttributes' => array(
                        'http://macedir.org/entity-category-support' => array('http://refeds.org/category/research-and-scholarship', 'http://www.geant.net/uri/dataprotection-code-of-conduct/v1'),
                        'urn:oasis:names:tc:SAML:attribute:assurance-certification' => array('https://refeds.org/sirtfi')
                    ),

                    'name' => array(
                        'en' => $idp->getInstituteName(),
                    ),
                    'SingleSignOnService' => $scheme.'://'.$idp->getHostname().'.'.$this->samlidp_hostname.$portSuffix.'/saml2/idp/SSOService.php',
                    'SingleLogoutService' => $scheme.'://'.$idp->getHostname().'.'.$this->samlidp_hostname.$portSuffix.'/saml2/idp/SingleLogoutService.php',
                    'SingleSignOnServiceBinding' => array(
                        'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
                        'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST'
                        ),
                    'SingleLogoutServiceBinding' => array(
                        'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
                        'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST'
                        )

                );
                foreach ($idp->getUsers() as $contact) {
                    $result[$idp->getEntityId($this->samlidp_hostname)]['contacts'][] = array(
                        'contactType' => 'technical',
                        'surName' => $contact->getSn(),
                        'givenName' => $contact->getGivenName(),
                        'emailAddress' => 'mailto:' . $contact->getEmail(),
                    );
                    $result[$idp->getEntityId($this->samlidp_hostname)]['contacts'][] = array(
                        'contactType' => 'other',
                        'surName' => $contact->getSn(),
                        'givenName' => $contact->getGivenName(),
                        'emailAddress' => 'mailto:' . $contact->getEmail(),
                        'attributes'        => [
                            'xmlns:remd'        => 'http://refeds.org/metadata',
                            'remd:contactType'  => 'http://refeds.org/metadata/contactType/security',
                        ],
                    );
                }

                if (!empty($idp->getLogo())) {
                    $result[$idp->getEntityId($this->samlidp_hostname)]['UIInfo']['Logo'] = array(
                        array(
                            'url' => 'https://'. $this->getSamlidpHostname().'/images/idp_logo/'.$idp->getLogo(),
                            'width' => 200,
                            'height' => 200,
                            ),
                        );
                }
                $o_elements = array();
                foreach ($idp->getOrganizationElements() as $orgElem) {
                    if ($orgElem->getType() == 'Name') {
                        $result[$idp->getEntityId($this->samlidp_hostname)]['OrganizationName'][$orgElem->getLang()] = $orgElem->getValue();
                        $result[$idp->getEntityId($this->samlidp_hostname)]['UIInfo']['DisplayName'][$orgElem->getLang()] = $orgElem->getValue();
                        $o_elements[] = $orgElem->getValue();
                    }
                    if ($orgElem->getType() == 'InformationUrl') {
                        $result[$idp->getEntityId($this->samlidp_hostname)]['OrganizationURL'][$orgElem->getLang()] = $orgElem->getValue();
                    }
                }

                # authproc dynamic parts
                $result[$idp->getEntityId($this->samlidp_hostname)]['authproc'][16] = array(
                    'class' => 'core:AttributeAdd',
                    'o' => $o_elements
                );
            }
        }

        return $result;
    }

    public function getUserSalt($username)
    {
        $idp = $this->em->getRepository('AppBundle:IdP')->findOneByHostname(str_replace('.' . $this->samlidp_hostname, '', $_SERVER['HTTP_HOST']));
        if (!$idp) {
            throw new EntityNotFoundException('No IdP found in the database.');
        }

        if (preg_match('/@/', $username)) {
            $idpUser = $this->em->getRepository('AppBundle:IdPUser')->findOneByEmail($username);
        } else {
            $idpUser = $this->em->getRepository('AppBundle:IdPUser')->findOneBy(
                array('username' => $username, 'IdP' => $idp)
            );
        }
        # for production use here could come some error handling
        if (is_null($idpUser)){
                return null;
        } else {
                return $idpUser->getSalt();
        }
    }

    public function getAuthsources()
    {
        $config = array();
        $config['admin'] = array('core:AdminPassword');
        $config['default-sp'] = array(
                'saml:SP',
                'entityID' => null,
                'idp' => null,
                'discoURL' => null,
                'privatekey' => 'attributes.' . $this->samlidp_hostname . '.key',
                'certificate' => 'attributes.' . $this->samlidp_hostname . '.crt',
                // 'privatekey' => 'attributes_samlidp_io.key',
                // 'certificate' => 'attributes_samlidp_io.crt',
                'attributes' => array(
                    'urn:oid:1.3.6.1.4.1.5923.1.1.1.6',
                    'urn:oid:2.16.840.1.113730.3.1.241',
                    'urn:oid:0.9.2342.19200300.100.1.3',
                    'urn:oid:1.3.6.1.4.1.5923.1.1.1.9',
                    'urn:oid:1.3.6.1.4.1.5923.1.1.1.10',
                    'urn:oid:2.5.4.10',
                    'urn:oid:1.3.6.1.4.1.25178.1.2.9',
                    'urn:oasis:names:tc:SAML:attribute:pairwise-id',
                    'urn:oasis:names:tc:SAML:attribute:subject-id'
                ),
                'name' => array(
                    'en' => 'attribute releasing tester',
                ),
        );

        $host = $_SERVER['HTTP_HOST'];

        if ($host != 'attributes.' . $this->samlidp_hostname) {
            $idp = $this->em->getRepository('AppBundle:IdP')->findOneByHostname(str_replace('.' . $this->samlidp_hostname, '', $host));
            $id_p_id = $idp->getId();
            $config['as-'.$idp->getHostname()] = array(
                'sqlauth:SQL',
                'dsn' => $this->database_type . ':host='.$this->database_host.';port='. $this->database_port. ';dbname='.$this->database_name,
                'username' => $this->database_user,
                'password' => $this->database_password,
                'query' => "SELECT username, email, givenName, surName, display_name, affiliation, (CASE scope.value WHEN '@' THEN domain.domain ELSE CONCAT_WS('.',scope.value, domain.domain) END) AS scope FROM idp_internal_mysql_user, scope, domain WHERE (username = :username OR email = :username) AND password = :password AND idp_internal_mysql_user.scope_id=scope.id AND scope.domain_id=domain.id AND (domain.idp_id=$id_p_id OR domain.idp_id IS NULL);",
                );
        }

        return $config;
    }

    public function addIdpAuditRecord($host, $username, $sp)
    {
        $idp = $this->em->getRepository('AppBundle:IdP')->findOneByHostname(str_replace('.' . $this->samlidp_hostname, '', $host));

        if (preg_match('/@/', $username)) {
            $idpUser = $this->em->getRepository('AppBundle:IdPUser')->findOneByEmail($username);
        } else {
            $idpUser = $this->em->getRepository('AppBundle:IdPUser')->findOneBy(
                array('username' => $username, 'IdP' => $idp)
            );
        }

        $now = new \DateTime();

        $newidpaudit = new IdPAudit($idpUser, $now, $idp, 'none');

        $this->em->persist($newidpaudit);
        $this->em->flush();
    }

    private function resolveCertificateDescriptor(IdP $idp, $requestHost)
    {
        $certBaseDir = $this->getCertBaseDir();
        $slug = trim((string) $idp->getHostname());
        $baseDomain = trim((string) $this->samlidp_hostname);
        $folderCandidates = array();
        $requestHost = trim((string) $requestHost);

        if ($requestHost !== '') {
            $folderCandidates[] = $requestHost;
            if ($baseDomain !== '' && substr($requestHost, -strlen($baseDomain)) === $baseDomain) {
                $prefix = rtrim(substr($requestHost, 0, -strlen($baseDomain)), '.');
                if ($prefix !== '') {
                    $folderCandidates[] = $prefix;
                }
            }
        }

        if ($slug !== '') {
            if ($baseDomain !== '') {
                $folderCandidates[] = $slug . '.' . $baseDomain;
            }
            $folderCandidates[] = $slug;
        }

        $folderCandidates[] = 'default';
        $folderCandidates[] = '';
        $folderCandidates = array_values(array_unique($folderCandidates));

        foreach ($folderCandidates as $folder) {
            foreach ($this->candidateCertificatePairs() as $pair) {
                $paths = $this->buildCertificatePaths($certBaseDir, $folder, $pair['cert'], $pair['key']);
                if (is_readable($paths['certPath']) && is_readable($paths['keyPath'])) {
                    $certContent = file_get_contents($paths['certPath']);
                    if ($certContent === false) {
                        continue;
                    }

                    return array(
                        'certPath' => $paths['certPath'],
                        'keyPath' => $paths['keyPath'],
                        'relativeCert' => $paths['relativeCert'],
                        'relativeKey' => $paths['relativeKey'],
                        'certData' => $this->normaliseCertificateBody($certContent),
                    );
                }
            }
        }

        $targetFolder = $slug !== '' ? $slug : 'default';
        $paths = $this->buildCertificatePaths($certBaseDir, $targetFolder, 'idp.crt.pem', 'idp.key.pem');
        $certBody = $idp->getCertPem();
        $keyBody = $idp->getCertKey();

        if ($certBody === null || $certBody === '' || $keyBody === null || $keyBody === '') {
            return null;
        }

        $this->ensureDirectory(dirname($paths['certPath']));

        file_put_contents($paths['certPath'], $certBody);
        file_put_contents($paths['keyPath'], $keyBody);
        @chmod($paths['keyPath'], 0600);

        return array(
            'certPath' => $paths['certPath'],
            'keyPath' => $paths['keyPath'],
            'relativeCert' => $paths['relativeCert'],
            'relativeKey' => $paths['relativeKey'],
            'certData' => $this->normaliseCertificateBody($certBody),
        );
    }

    private function candidateCertificatePairs()
    {
        return array(
            array('cert' => 'idp.crt.pem', 'key' => 'idp.key.pem'),
            array('cert' => 'idp.crt', 'key' => 'idp.key'),
        );
    }

    private function buildCertificatePaths($baseDir, $folder, $certFile, $keyFile)
    {
        $baseDir = rtrim($baseDir, '/');
        $folder = trim((string) $folder, '/');

        if ($folder === '') {
            $certPath = $baseDir . '/' . $certFile;
            $keyPath = $baseDir . '/' . $keyFile;
            $relativeCert = $certFile;
            $relativeKey = $keyFile;
        } else {
            $certPath = $baseDir . '/' . $folder . '/' . $certFile;
            $keyPath = $baseDir . '/' . $folder . '/' . $keyFile;
            $relativeCert = $folder . '/' . $certFile;
            $relativeKey = $folder . '/' . $keyFile;
        }

        return array(
            'certPath' => $certPath,
            'keyPath' => $keyPath,
            'relativeCert' => $relativeCert,
            'relativeKey' => $relativeKey,
        );
    }

    private function normaliseCertificateBody($certificate)
    {
        $certificate = trim((string) $certificate);
        if ($certificate === '') {
            return '';
        }

        $lines = preg_split('/\r?\n/', $certificate);
        $filtered = array();
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '-----BEGIN') === 0 || strpos($line, '-----END') === 0) {
                continue;
            }
            $filtered[] = $line;
        }

        return implode("\n", $filtered);
    }

    private function getCertBaseDir()
    {
        $projectRoot = realpath(__DIR__ . '/../../../..');
        if ($projectRoot === false) {
            $projectRoot = dirname(dirname(dirname(dirname(__DIR__))));
        }

        $certDir = $projectRoot . '/certs';
        $this->ensureDirectory($certDir);

        return $certDir;
    }

    private function ensureDirectory($directory)
    {
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Unable to create directory %s', $directory));
        }
    }
}
