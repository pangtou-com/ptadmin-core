<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Contracts;

use PTAdmin\Schema\Definition\Schema;

/**
 * Schema 归一化、协议校验和跨语言 checksum 服务边界。
 */
interface DefinitionServiceInterface
{
    /** 归一化输入并补齐默认结构。 */
    public function normalize(array|Schema $schema): Schema;
    /** @return list<array{code:string,path:string,message:string}> */
    public function validate(array|Schema $schema): array;
    public function checksum(array|Schema $schema): string;
}
