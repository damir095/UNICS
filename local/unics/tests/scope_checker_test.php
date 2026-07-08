<?php
namespace local_unics;

use local_unics\identity\scope_checker;

/**
 * Тесты скоуп-проверок иерархии регион > муниципалитет > организация
 * (этап 5.2 аудита). Это сквозной механизм авторизации управленческих
 * страниц - фиксируем семантику до этапа 4 (тонкие capabilities).
 *
 * @package local_unics
 */
#[\PHPUnit\Framework\Attributes\CoversClass(scope_checker::class)]
final class scope_checker_test extends \advanced_testcase {

    /** @var array созданная иерархия: R1 > D1 > O1; R2 > D2 > O2 */
    private array $h = [];

    protected function setUp(): void {
        parent::setUp();
        global $DB;
        $this->resetAfterTest();

        $r1 = $DB->insert_record('unics_regions', (object)['name' => 'Регион 1']);
        $r2 = $DB->insert_record('unics_regions', (object)['name' => 'Регион 2']);
        $d1 = $DB->insert_record('unics_districts', (object)['region_id' => $r1, 'name' => 'Округ 1']);
        $d2 = $DB->insert_record('unics_districts', (object)['region_id' => $r2, 'name' => 'Округ 2']);
        $o1 = $DB->insert_record('unics_organizations', (object)['district_id' => $d1, 'name' => 'Школа 1']);
        $o2 = $DB->insert_record('unics_organizations', (object)['district_id' => $d2, 'name' => 'Школа 2']);
        $this->h = compact('r1', 'r2', 'd1', 'd2', 'o1', 'o2');
    }

    /** Пользователь с заданным скоупом в unics_user_org. */
    private function user_with_scope(?int $region, ?int $district, ?int $org, int $role): int {
        global $DB;
        $user = $this->getDataGenerator()->create_user();
        $DB->insert_record('unics_user_org', (object)[
            'mdl_user_id'     => $user->id,
            'unics_role'      => $role,
            'region_id'       => $region,
            'district_id'     => $district,
            'organization_id' => $org,
        ]);
        return (int)$user->id;
    }

    public function test_region_scope_covers_own_branch_only(): void {
        $uid = $this->user_with_scope($this->h['r1'], null, null, 1);

        $this->assertTrue(scope_checker::user_can_access_region($uid, $this->h['r1']));
        $this->assertFalse(scope_checker::user_can_access_region($uid, $this->h['r2']));
        $this->assertTrue(scope_checker::user_can_access_district($uid, $this->h['d1']));
        $this->assertFalse(scope_checker::user_can_access_district($uid, $this->h['d2']));
        $this->assertTrue(scope_checker::user_can_access_org($uid, $this->h['o1']));
        $this->assertFalse(scope_checker::user_can_access_org($uid, $this->h['o2']));
    }

    public function test_district_scope(): void {
        $uid = $this->user_with_scope(null, $this->h['d1'], null, 9);

        $this->assertFalse(scope_checker::user_can_access_region($uid, $this->h['r1']));
        $this->assertTrue(scope_checker::user_can_access_district($uid, $this->h['d1']));
        $this->assertFalse(scope_checker::user_can_access_district($uid, $this->h['d2']));
        $this->assertTrue(scope_checker::user_can_access_org($uid, $this->h['o1']));
        $this->assertFalse(scope_checker::user_can_access_org($uid, $this->h['o2']));
    }

    public function test_org_scope_is_narrowest(): void {
        $uid = $this->user_with_scope(null, null, $this->h['o1'], 4);

        $this->assertTrue(scope_checker::user_can_access_org($uid, $this->h['o1']));
        $this->assertFalse(scope_checker::user_can_access_org($uid, $this->h['o2']));
        // Орг-скоуп не даёт прав на район/регион как сущности.
        $this->assertFalse(scope_checker::user_can_access_district($uid, $this->h['d1']));
        $this->assertFalse(scope_checker::user_can_access_region($uid, $this->h['r1']));
    }

    public function test_no_scope_no_access(): void {
        $user = $this->getDataGenerator()->create_user();
        $uid = (int)$user->id;

        $this->assertFalse(scope_checker::user_can_access_org($uid, $this->h['o1']));
        $this->assertFalse(scope_checker::user_can_access_district($uid, $this->h['d1']));
        $this->assertFalse(scope_checker::user_can_access_region($uid, $this->h['r1']));
    }

    public function test_user_can_access_user_via_student_org(): void {
        global $DB;
        // Target - учащийся школы O1.
        $target = $this->getDataGenerator()->create_user();
        $DB->insert_record('unics_students', (object)[
            'mdl_user_id'     => $target->id,
            'organization_id' => $this->h['o1'],
        ]);

        $orgmeth  = $this->user_with_scope(null, null, $this->h['o1'], 4);
        $distmeth = $this->user_with_scope(null, $this->h['d1'], null, 9);
        $alien    = $this->user_with_scope(null, $this->h['d2'], null, 9);

        $this->assertTrue(scope_checker::user_can_access_user($orgmeth, (int)$target->id));
        $this->assertTrue(scope_checker::user_can_access_user($distmeth, (int)$target->id));
        $this->assertFalse(scope_checker::user_can_access_user($alien, (int)$target->id));
        // Target без единой привязки - безопасный default: доступа нет.
        $loner = $this->getDataGenerator()->create_user();
        $this->assertFalse(scope_checker::user_can_access_user($orgmeth, (int)$loner->id));
    }
}
