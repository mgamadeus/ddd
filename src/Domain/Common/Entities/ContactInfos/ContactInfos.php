<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\ContactInfos;

use DDD\Domain\Base\Entities\DefaultObject;
use DDD\Domain\Base\Entities\ObjectSet;
use DDD\Domain\Common\Entities\ContactInfos\ContactInfo;
use DDD\Domain\Common\Entities\ContactInfos\Email;
use DDD\Domain\Common\Entities\ContactInfos\PhoneNumber;
use ReflectionException;

/**
 * @property Email|PhoneNumber[] $elements;
 * @method Email|PhoneNumber getByUniqueKey(string $uniqueKey)
 * @method Email|PhoneNumber[] getElements
 * @method Email|PhoneNumber first
 */
class ContactInfos extends ObjectSet
{

    public function getByScope(string $scope)
    {
        foreach ($this->elements as $element) {
            if ($element->scope == $scope) {
                return $element;
            }
        }

        return null;
    }

    /**
     * Compares ContactInfos to other ContactInfos, and uses herefore only Scopes that are available on both
     * If $forcePhone is true, then scope PHONE will be forced, even if not available on both objects
     * @param DefaultObject|null $other
     * @param bool $forcePhone
     * @return bool
     * @throws ReflectionException
     */
    public function isEqualTo(?DefaultObject $other = null, bool $forcePhone = false): bool
    {
        if (!$other) {
            return false;
        }
        // avoid comparing with contact scopes that are not available in all constellations
        $thisCopy = new ContactInfos();

        // we compare only scopes that are present in both ContactInfos objects
        $scopesAvailable = $this->getScopesAvailable();
        $otherScopesAvailable = $other->getScopesAvailable();
        $scopesAvailableOnBothObjects = array_intersect($scopesAvailable, $otherScopesAvailable);
        if ($forcePhone) {
            if (in_array(PhoneNumber::SCOPE_PHONE, $scopesAvailable) || in_array(
                    PhoneNumber::SCOPE_PHONE,
                    $otherScopesAvailable
                )) {
                $scopesAvailableOnBothObjects[] = PhoneNumber::SCOPE_PHONE;
            }
            // we add mobile phone only, if either this or other has mobile phone AND if the element having mobile phone has no phone
            if (($mobileInScope = in_array(
                    PhoneNumber::SCOPE_MOBILE_PHONE,
                    $scopesAvailable
                )) || ($mobileInOtherScopes = in_array(PhoneNumber::SCOPE_MOBILE_PHONE, $otherScopesAvailable))) {
                // if phone is available on both, we do addd mobile phone to scopes, as only phone is to be compared
                if (!(in_array(PhoneNumber::SCOPE_PHONE, $scopesAvailable) && in_array(
                        PhoneNumber::SCOPE_PHONE,
                        $otherScopesAvailable
                    ))) {
                    $scopesAvailableOnBothObjects[] = PhoneNumber::SCOPE_MOBILE_PHONE;
                }
            }
        }

        foreach ($this->getElements() as $contactInfo) {
            if (($contactInfo->type == ContactInfo::TYPE_EMAIL && $contactInfo->scope != Email::SCOPE_EMAIL_BUSINESS)
                || $contactInfo->scope == PhoneNumber::SCOPE_FAX || !in_array(
                    $contactInfo->scope,
                    $scopesAvailableOnBothObjects
                )) {
                continue;
            } else {
                $contactInfoClone = $contactInfo->clone();
                $thisCopy->add($contactInfoClone);
            }
        }
        $otherCopy = new ContactInfos();
        foreach ($other->getElements() as $contactInfo) {
            if (($contactInfo->type == ContactInfo::TYPE_EMAIL && $contactInfo->scope != Email::SCOPE_EMAIL_BUSINESS)
                || $contactInfo->scope == PhoneNumber::SCOPE_FAX || !in_array(
                    $contactInfo->scope,
                    $scopesAvailableOnBothObjects
                )) {
                continue;
            } else {
                $contactInfoClone = $contactInfo->clone();
                $otherCopy->add($contactInfoClone);
            }
        }
        // if one element has mobile phone and the other has phone and both are equal, this has to be threatened special
        // the context is, that we sync mobile phone as phone if no phone is available
        // we check if there is in $this a phone and in other a mobile phone or in other a mobile phone and in this a phone,
        // that are identicall, in this case we remove the entries so they are not part of comparison anymore
        if (
            ($thisPhone = $thisCopy->getByScope(PhoneNumber::SCOPE_PHONE)) && ($otherPhone = $otherCopy->getByScope(
                PhoneNumber::SCOPE_MOBILE_PHONE
            )) ||
            ($thisPhone = $thisCopy->getByScope(
                PhoneNumber::SCOPE_MOBILE_PHONE
            )) && ($otherPhone = $otherCopy->getByScope(PhoneNumber::SCOPE_PHONE))
        ) {
            if ($thisPhone->value == $otherPhone->value) {
                $thisCopy->remove($thisPhone);
                $otherCopy->remove($otherPhone);
            }
        }
        foreach ($thisCopy->getElements() as $thisElement) {
            $otherElement = $otherCopy->getByUniqueKey($thisElement->uniqueKey());
            if (!$otherElement) {
                return false;
            }
            if (!$thisElement->isEqualTo($otherElement)) {
                return false;
            }
        }
        foreach ($otherCopy->getElements() as $otherElement) {
            $thisElement = $thisCopy->getByUniqueKey($otherElement->uniqueKey());
            if (!$thisElement) {
                return false;
            }
            if (!$otherElement->isEqualTo($thisElement)) {
                return false;
            }
        }
        return true;
    }

    /**
     * @return string[] Returns all scopes available
     */
    public function getScopesAvailable(): array
    {
        $scopes = [];
        foreach ($this->getElements() as $element) {
            $scopes[] = $element->scope;
        }
        return $scopes;
    }
}
