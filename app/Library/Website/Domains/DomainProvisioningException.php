<?php

namespace App\Library\Website\Domains;

use RuntimeException;

/**
 * Thrown by ForgeDomainProvisioner on any failed provider call — never
 * caught silently by the provisioner itself, so WebsiteDomainService
 * decides what a failure means for the domain row's own status.
 */
class DomainProvisioningException extends RuntimeException {}
