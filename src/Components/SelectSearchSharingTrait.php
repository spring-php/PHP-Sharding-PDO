<?php
/**
 * PhpShardingPdo  file.
 * @author linyushan  <1107012776@qq.com>
 * @link https://www.developzhe.com/
 * @package https://github.com/1107012776/PHP-Sharding-PDO
 * @copyright Copyright &copy; 2019-2021
 * @license https://github.com/1107012776/PHP-Sharding-PDO/blob/master/LICENSE
 */

namespace PhpShardingPdo\Components;

use \PhpShardingPdo\Core\StatementShardingPdo;

/**
 * 查询
 * User: linyushan
 * Date: 2019/8/2
 * Time: 14:57
 */
trait SelectSearchSharingTrait
{

    private $fetch_style = \PDO::FETCH_ASSOC;

    private $attr_cursor = \PDO::CURSOR_FWDONLY;


    private function _defaultSearch()
    {
        $result = [];
        $sqlArr = [];
        if (!empty($this->offset)  //存在偏移的时候，需要特殊处理
            && (
                empty($this->getCurrentExecDb())  //没有找到具体库
                || empty($this->_current_exec_table)  //没有找到具体表
            )
        ) {
            $this->_limit_str = ' limit ' . strval($this->offset + $this->offset_limit);  //分布式分页，获取的个数
        }
        if (empty($this->_current_exec_table) && empty($this->_table_name_index)) {  //全部扫描
            $sql = 'select ' . $this->_field_str . ' from ' . $this->getExecSelectString($this->_table_name) . $this->_condition_str . $this->_group_str . $this->_order_str . $this->_limit_str;
        } elseif (empty($this->_current_exec_table) && !empty($this->_table_name_index)) {
            foreach ($this->_table_name_index as $tableName) {
                $sqlArr[] = 'select ' . $this->_field_str . ' from ' . $this->getExecSelectString($tableName) . $this->_condition_str . $this->_group_str . $this->_order_str . $this->_limit_str;
            }
        } else {
            $sql = 'select ' . $this->_field_str . ' from ' . $this->getExecSelectString($this->_current_exec_table) . $this->_condition_str . $this->_group_str . $this->_order_str . $this->_limit_str;
        }
        $statementArr = [];
        if (empty($this->getCurrentExecDb())) {  //没有找到数据库
            $searchFunc = function ($sql) use (&$statementArr) {
                foreach ($this->_databasePdoInstanceMap() as $key => $db) {
                    /**
                     * @var \PDOStatement $statement
                     * @var \PDO $db
                     */
                    $statement = $statementArr[] = $db->prepare($sql, array(\PDO::ATTR_CURSOR => $this->attr_cursor));
                    $res[$key] = $statement->execute($this->_condition_bind);
                    $this->_addSelectSql($sql, $this->_condition_bind, $db);
                    if (empty($res[$key])) {
                        $this->_sqlErrors[] = [$db->getDsn() => $statement->errorInfo()];
                    }
                }
            };
            if (!empty($sqlArr)) {  //扫描多张表
                foreach ($sqlArr as $sql) {
                    $searchFunc($sql);
                }
            } else {
                $searchFunc($sql);
            }
            if (empty($statementArr)) {
                return false;
            }
            if (!empty($limit = $this->_getLimitReCount())) {
                return $this->_limitDefaultSearch($statementArr, $limit);
            } else {
                /**
                 * @var \PDOStatement $s
                 */
                foreach ($statementArr as $s) {
                    $tmp = $s->fetchAll($this->fetch_style);
                    !empty($tmp) && $result = array_merge($result, $tmp);
                }
            }
        } else {
            empty($sqlArr) && $sqlArr = [$sql];
            foreach ($sqlArr as $sql) {
                /**
                 * @var \PDOStatement $statement
                 */
                $statement = $statementArr[] = $this->getCurrentExecDb()->prepare($sql, array(\PDO::ATTR_CURSOR => $this->attr_cursor));
                $res = $statement->execute($this->_condition_bind);
                $this->_addSelectSql($sql, $this->_condition_bind, $this->getCurrentExecDb());
                if (empty($res)) {
                    $this->_sqlErrors[] = [$this->getCurrentExecDb()->getDsn() => $statement->errorInfo()];
                }
            }
            if (count($statementArr) > 1) {
                if (!empty($limit = $this->_getLimitReCount())) {
                    return $this->_limitDefaultSearch($statementArr, $limit);
                } else {
                    /**
                     * @var \PDOStatement $s
                     */
                    foreach ($statementArr as $s) {
                        $tmp = $s->fetchAll($this->fetch_style);
                        !empty($tmp) && $result = array_merge($result, $tmp);
                    }
                }
            } else {
                $tmp = $statement->fetchAll($this->fetch_style);
                !empty($tmp) && $result = array_merge($result, $tmp);
            }
        }
        return $result;
    }


    /**
     * 分组搜索
     * @return array|boolean
     */
    private function _groupSearch()
    {
        $sqlArr = [];
        $groupField = $this->_getGroupField();
        $intersect = array_intersect($groupField, $this->_field);
        if (empty($intersect) && $this->_field_str != '*') {
            $this->_field_str .= ',' . $groupField[0];
        }
        if (empty($this->_current_exec_table) && empty($this->_table_name_index)) {  //全部扫描
            $sql = 'select ' . $this->_field_str . ' from ' . $this->getExecSelectString($this->_table_name) . $this->_condition_str . $this->_group_str . $this->_order_str;
        } elseif (empty($this->_current_exec_table) && !empty($this->_table_name_index)) {
            foreach ($this->_table_name_index as $tableName) {
                $sqlArr[] = 'select ' . $this->_field_str . ' from ' . $this->getExecSelectString($tableName) . $this->_condition_str . $this->_group_str . $this->_order_str;
            }
        } else {
            $sql = 'select ' . $this->_field_str . ' from ' . $this->getExecSelectString($this->_current_exec_table) . $this->_condition_str . $this->_group_str . $this->_order_str;
        }
        empty($sql) && $sql = '';
        return $this->_groupShardingSearch($sqlArr, $sql);
    }


    private function _search()
    {
        $this->_pare();
        if (!empty($this->_group_str)) {  //存在group by
            return $this->_groupSearch();
        }
        return $this->_defaultSearch();
    }

    /**
     * 存在limit的时候查询
     * @param $statementArr
     * @param $limit
     * @return array
     */
    private function _limitDefaultSearch($statementArr, $limit)
    {
        $result = [];
        if (!empty($this->_order_str)) {
            $statementCurrentRowObjArr = [];
            $orderArr = $this->_getOrderField();
            /**
             * @var \PDOStatement $s
             */
            foreach ($statementArr as $index => $s) {
                $statementCurrentRowObjArr[] = new StatementShardingPdo($s);
            }
            while ($limit > 0) {   //limit获取值核心方法
                StatementShardingPdo::reSort($statementCurrentRowObjArr, $orderArr);
                if (empty($statementCurrentRowObjArr)) {
                    break;
                }
                /**
                 * @var StatementShardingPdo $que
                 */
                $que = $statementCurrentRowObjArr[0];
                $tmp = $que->getFetch();
                if (empty($tmp)) {
                    array_shift($statementCurrentRowObjArr);
                    if (empty($statementCurrentRowObjArr)) {
                        break;
                    }
                    $statementCurrentRowObjArr = array_values($statementCurrentRowObjArr);
                    continue;
                }
                $this->offset--;
                if ($this->offset >= 0) {  //这边的偏移性能比较差，最后在条件上面加一个范围查询的比如 id > 110000 之类的降低偏移的压力
                    continue;
                }
                $limit--;
                array_push($result, $tmp);
            }
        } else {
            /**
             * @var \PDOStatement $s
             */
            foreach ($statementArr as $index => $s) {
                while ($limit > 0) {
                    $tmp = $s->fetch($this->fetch_style);
                    if (empty($tmp)) {
                        break;
                    }
                    if (isset($tmp['total_count_num'])
                        && count($tmp) == 1) {  //count查询，这种特殊情况下
                        array_push($result, $tmp);
                        continue;
                    }
                    $limit--;
                    array_push($result, $tmp);
                }
            }
        }
        return $result;
    }

    /**
     * 获取orderBy字段
     * @return $order =>
     * [
     *    [
     *        'id','asc',
     *    ],
     *    [
     *       'create_time','desc'
     *    ]
     * ]
     */
    private function _getOrderField()
    {
        $order = str_replace(' order by ', '', $this->_order_str);
        if (strstr($order, ',')) {
            $order = explode(',', $order);
        }
        if (is_array($order)) {  //多个order by
            foreach ($order as &$v) {
                $v = trim($v);
                $v = explode(' ', $v);
                $v = array_filter($v);  //去空值
            }
        } else {
            $order = trim($order);
            $order = explode(' ', $order);
            $order = array_filter($order); //去空值
            $order = [$order];
        }
        return $order;
    }
}
