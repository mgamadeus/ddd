<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Base\Entities\QueryOptions;

use DDD\Domain\Base\Entities\QueryOptions\ExpandOptions;
use PHPUnit\Framework\TestCase;

/**
 * The rename of a read-rights base alias when the rights are embedded into an expand: only the alias and the aliases
 * derived from it change, never a field of another alias that starts with the base alias.
 */
class ExpandRightsBaseAliasPatternTest extends TestCase
{
    public function testAFieldOfAnotherAliasThatStartsWithTheBaseAliasIsNotRenamed(): void
    {
        $where = 'site.id in (select user_site.site_id from X user_site where user_site.user_id = :u)';

        $this->assertSame(
            '_rights1_location.id in (select user_site.site_id from X user_site where user_site.user_id = :u)',
            preg_replace(ExpandOptions::getRightsBaseAliasPattern('site'), '_rights1_location', $where)
        );
    }

    public function testTheAliasAndItsDerivedAliasesAreRenamed(): void
    {
        $join = 'LEFT JOIN site.projects site_projects_rights WITH site_projects_rights.isActive = 1';

        $this->assertSame(
            'LEFT JOIN _rights2_location.projects _rights2_location_projects_rights WITH _rights2_location_projects_rights.isActive = 1',
            preg_replace(ExpandOptions::getRightsBaseAliasPattern('site'), '_rights2_location', $join)
        );
    }

    public function testLongerNamesAndNamesEndingInTheAliasStay(): void
    {
        $where = 'siteGroup.id = 1 AND AIConversationMessage.id = 2 AND user_site.id = 3';

        $this->assertSame($where, preg_replace(ExpandOptions::getRightsBaseAliasPattern('site'), 'x', $where));
        $this->assertSame($where, preg_replace(ExpandOptions::getRightsBaseAliasPattern('AIConversation'), 'x', $where));
    }
}
