<?php
namespace local_unics;

use local_unics\output\availability_tree;

#[\PHPUnit\Framework\Attributes\CoversClass(availability_tree::class)]
final class availability_tree_test extends \advanced_testcase {

    public function test_flat_tree_returns_leaves(): void {
        $json = '{"op":"&","c":[{"type":"group","id":55}],"showc":[false]}';
        $this->assertSame([['type' => 'group', 'id' => 55]], availability_tree::leaves($json));
    }

    public function test_nested_tree_is_flattened(): void {
        $json = '{"op":"&","c":[{"type":"group","id":63},'
              . '{"op":"|","c":[{"type":"completion","cm":576,"e":1},{"type":"date","d":">=","t":100}]}]}';
        $leaves = availability_tree::leaves($json);
        $this->assertCount(3, $leaves);
        $this->assertSame('group', $leaves[0]['type']);
        $this->assertSame('completion', $leaves[1]['type']);
        $this->assertSame('date', $leaves[2]['type']);
    }

    public function test_empty_and_broken_input_give_empty_array(): void {
        $this->assertSame([], availability_tree::leaves(null));
        $this->assertSame([], availability_tree::leaves(''));
        $this->assertSame([], availability_tree::leaves('не json'));
        $this->assertSame([], availability_tree::leaves('{"op":"&","c":[]}'));
    }
}
