<?php
/**
 * This file is part of the Jacobine package.
 *
 * (c) Andreas Grunwald <andygrunwald@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Jacobine\Tests\Consumer\Analysis;

use Jacobine\Tests\Consumer\ConsumerTestAbstract;
use Jacobine\Consumer\Analysis\GithubLinguist;

/**
 * Class GithubLinguistTest
 *
 * Unit test class for \Jacobine\Consumer\Analysis\GithubLinguist
 *
 * @package Jacobine\Tests\Consumer\Analysis
 * @author Andy Grunwald <andygrunwald@gmail.com>
 */
class GithubLinguistTest extends ConsumerTestAbstract
{

    public function setUp()
    {
        $this->consumer = new GithubLinguist();
    }
}
