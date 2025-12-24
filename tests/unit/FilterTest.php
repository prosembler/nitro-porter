<?php

use PHPUnit\Framework\TestCase;

final class FilterTest extends TestCase
{
    /**
     * @covers \vanillaPhoto
     */
    public function testParseRender(): void
    {
        $photoTests = [
            // Add 'p' - Vanilla origin
            'userpics/396/YGIC427MJADQ.jpg' => 'userpics/396/pYGIC427MJADQ.jpg',
            'uploads/userpics/820/4J95NK90AFDT.jpeg' => 'uploads/userpics/820/p4J95NK90AFDT.jpeg',
            // Add 'p' - vBulletin -> Vanilla origin
            'userpics/avatar11_4.gif' => 'userpics/pavatar11_4.gif',
            // No change; URL
            'https://example.com/forum/uploads/userpics/396/YGIC427MJADQ.jpg'
            => 'https://example.com/forum/uploads/userpics/396/YGIC427MJADQ.jpg',
            // No change; not Vanilla origin - filename pattern
            'userpics/aaa919.gif' => 'userpics/aaa919.gif',
            // No change; not Vanilla origin - extension
            'userpics/396/YGIC427MJADQ.ext' => 'userpics/396/YGIC427MJADQ.ext',
        ];
        foreach ($photoTests as $input => $expectedOutput) {
            $testOutput = \vanillaPhoto($input);
            $this->assertEquals($testOutput, $expectedOutput);
        }
    }
}
