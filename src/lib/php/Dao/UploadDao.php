<?php
/*
Copyright (C) 2014, Siemens AG
Authors: Andreas Würl, Steffen Weber

This program is free software; you can redistribute it and/or
modify it under the terms of the GNU General Public License
version 2 as published by the Free Software Foundation.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License along
with this program; if not, write to the Free Software Foundation, Inc.,
51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
*/

namespace Fossology\Lib\Dao;

use DateTime;
use Fossology\Lib\Data\Upload\Upload;
use Fossology\Lib\Data\UploadStatus;
use Fossology\Lib\Data\Tree\Item;
use Fossology\Lib\Data\Tree\ItemTreeBounds;
use Fossology\Lib\Db\DbManager;
use Fossology\Lib\Exception;
use Fossology\Lib\Util\Object;
use Monolog\Logger;

require_once(dirname(dirname(__FILE__)) . "/common-dir.php");

class UploadDao extends Object
{
  /** @var DbManager */
  private $dbManager;
  /** @var Logger */
  private $logger;

  public function __construct(DbManager $dbManager)
  {
    $this->dbManager = $dbManager;
    $this->logger = new Logger(self::className());
  }

  /**
   * @param int $itemId
   * @param UploadTreeDao $uploadTreeView
   * @return Item
   */
  public function getUploadEntryFromView($itemId, UploadTreeDao $uploadTreeView)
  {
    $uploadTreeViewQuery = $uploadTreeView->asCTE();
    $stmt = __METHOD__ . ".$uploadTreeViewQuery";
    $uploadEntry = $this->dbManager->getSingleRow("$uploadTreeViewQuery SELECT * FROM UploadTreeView WHERE uploadtree_pk = $1",
        array($itemId), $stmt);

    return $uploadEntry ? $this->createItem($uploadEntry, $uploadTreeView->getUploadTreeTableName()) : null;
  }

  /**
   * @param $uploadTreeId
   * @param string $uploadTreeTableName
   * @return array
   */
  public function getUploadEntry($uploadTreeId, $uploadTreeTableName = "uploadtree")
  {
    $stmt = __METHOD__ . ".$uploadTreeTableName";
    $uploadEntry = $this->dbManager->getSingleRow("SELECT * FROM $uploadTreeTableName WHERE uploadtree_pk = $1",
        array($uploadTreeId), $stmt);
    if ($uploadEntry) {
      $uploadEntry['tablename'] = $uploadTreeTableName;
    }
    return $uploadEntry;
  }

  /**
   * @param int $uploadId
   * @return Upload|null
   */
  public function getUpload($uploadId)
  {
    $stmt = __METHOD__;
    $row = $this->dbManager->getSingleRow("SELECT * FROM upload WHERE upload_pk = $1",
        array($uploadId), $stmt);

    return $row ? Upload::createFromTable($row) : null;
  }

  /**
   * @param $uploadTreeId
   * @param $uploadTreeTableName
   * @return ItemTreeBounds
   */
  public function getItemTreeBounds($uploadTreeId, $uploadTreeTableName = "uploadtree")
  {
    $uploadEntryData = $this->getUploadEntry($uploadTreeId, $uploadTreeTableName);
    return $this->createItemTreeBounds($uploadEntryData, $uploadTreeTableName);
  }

  /**
   * @param $uploadTreeId
   * @param $uploadId
   * @return ItemTreeBounds
   */
  public function getItemTreeBoundsFromUploadId($uploadTreeId, $uploadId)
  {
    $uploadTreeTableName = $this->getUploadtreeTableName($uploadId);
    return $this->getItemTreeBounds($uploadTreeId, $uploadTreeTableName);
  }

  /**
   * @param int $uploadId
   * @param string|null
   * @throws Exception
   * @return ItemTreeBounds
   */
  public function getParentItemBounds($uploadId,$uploadTreeTableName=NULL)
  {
    if ($uploadTreeTableName === null)
    {
      $uploadTreeTableName = $this->getUploadtreeTableName($uploadId);
    }

    $stmt = __METHOD__ . ".$uploadTreeTableName";
    $parameters = array();
    $uploadCondition = $this->handleUploadIdForTable($uploadTreeTableName, $uploadId, $parameters);

    $uploadEntryData = $this->dbManager->getSingleRow("
SELECT * FROM $uploadTreeTableName
        WHERE parent IS NULL
              $uploadCondition
          ",
        $parameters, $stmt);

    return $this->createItemTreeBounds($uploadEntryData, $uploadTreeTableName);
  }

  /**
   * @param ItemTreeBounds $itemTreeBounds
   * @return int
   */
  public function countPlainFiles(ItemTreeBounds $itemTreeBounds)
  {
    $uploadTreeTableName = $itemTreeBounds->getUploadTreeTableName();

    $stmt = __METHOD__ . ".$uploadTreeTableName";
    $parameters = array($itemTreeBounds->getLeft(), $itemTreeBounds->getRight());
    $uploadCondition = $this->handleUploadIdForTable($uploadTreeTableName, $itemTreeBounds->getUploadId(), $parameters);

    $row = $this->dbManager->getSingleRow("SELECT count(*) as count FROM $uploadTreeTableName
        WHERE  lft BETWEEN $1 AND $2
          $uploadCondition
          AND ((ufile_mode & (3<<28))=0)
          AND pfile_fk != 0",
        $parameters, $stmt);
    $fileCount = intval($row["count"]);
    return $fileCount;
  }

  private function handleUploadIdForTable($uploadTreeTableName, $uploadId, &$parameters)
  {
    if ($uploadTreeTableName === "uploadtree" || $uploadTreeTableName === "uploadtree_a") {
      $parameters[] = $uploadId;
      return " AND upload_fk = $" . count($parameters) . " ";
    } else {
      return "";
    }
  }

  /**
   * @return array
   */
  public function getStatusTypeMap()
  {
    global $container;
    /** @var UploadStatus */
    $uploadStatus = $container->get('upload_status.types');
    return $uploadStatus->getMap();
  }

  /**
   * \brief Get the uploadtree table name for this upload_pk
   *        If upload_pk does not exist, return "uploadtree".
   *
   * \param $upload_pk
   *
   * \return uploadtree table name
   */
  public function getUploadtreeTableName($uploadId)
  {
    if (!empty($uploadId))
    {
      $statementName = __METHOD__;
      $row = $this->dbManager->getSingleRow(
          "select uploadtree_tablename from upload where upload_pk=$1",
          array($uploadId),
          $statementName
      );
      if (!empty($row['uploadtree_tablename']))
      {
        return $row['uploadtree_tablename'];
      }
    }
    return "uploadtree";
  }

  /**
   * @param int $uploadId
   * @param int $itemId
   * @return Item|null
   */
  public function getNextItem($uploadId, $itemId, $options = null)
  {
    return $this->getItemByDirection($uploadId, $itemId, self::DIR_FWD, $options);
  }

  /**
   * @param $uploadId
   * @param $itemId
   * @return mixed
   */
  public function getPreviousItem($uploadId, $itemId, $options = null)
  {
      return $this->getItemByDirection($uploadId, $itemId, self::DIR_BCK, $options);
  }

  const DIR_FWD = 1;
  const DIR_BCK = -1;
  const NOT_FOUND = null;

  /**
   * @param $uploadId
   * @param $itemId
   * @param $direction
   * @return mixed
   */
  public function getItemByDirection($uploadId, $itemId, $direction, $options)
  {
    $uploadTreeTableName = $this->getUploadtreeTableName($uploadId);
    $options['ut.filter'] = " OR ut.ufile_mode & (1<<29) <> 0 OR ut.uploadtree_pk = $itemId";
    $uploadTreeView = new UploadTreeDao($uploadId, $options, $uploadTreeTableName);

    $item = $this->getUploadEntryFromView($itemId, $uploadTreeView);

    $enterFolders = $direction == self::DIR_FWD;
    while (true)
    {
      $nextItem = $this->findNextItem($item, $direction, $uploadTreeView, $enterFolders);

      if ($nextItem !== null)
      {
        return $nextItem;
      }

      if ($item->hasParent())
      {
        $item = $this->getUploadEntryFromView($item->getParentId(), $uploadTreeView);
        $enterFolders = false;
      } else
      {
        return self::NOT_FOUND;
      }
    }
  }

  /**
   * @param $item
   * @param $direction
   * @param $uploadTreeView
   * @return mixed
   */
  protected function findNextItem(Item $item, $direction, UploadTreeDao $uploadTreeView, $enterFolders = true)
  {
    if ($item->getParentId() === null && $direction !== self::DIR_FWD)
    {
      return self::NOT_FOUND;
    }

    $enterItem = $item->isContainer() && $enterFolders;

    $indexIncrement = $direction == self::DIR_FWD ? 1 : -1;

    $parent = $item->getParentId();
    $parentSize = $this->getParentSize($parent, $uploadTreeView);
    $targetIndex = $this->getItemIndex($item, $uploadTreeView);

    $nextItem = null;
    $firstIteration = true;
    while (($targetIndex >= 0 && $targetIndex < $parentSize))
    {
      if ($firstIteration)
      {
        $firstIteration = false;
        if ($enterItem)
        {
            $nextItem = $this->getNewItemByIndex(
                $item->getId(),
                $direction == self::DIR_FWD ? 0 : $this->getParentSize($item->getId(), $uploadTreeView) - 1,
                $uploadTreeView
            );
        }
      } else
      {
        $nextItem = $this->getNewItemByIndex($parent, $targetIndex, $uploadTreeView);
      }

      if ($nextItem !== null && $nextItem->isContainer())
      {
        $nextItem = $this->findNextItem($nextItem, $direction, $uploadTreeView);
      }

      if ($nextItem !== null)
      {
        return $nextItem;
      }

      $targetIndex += $indexIncrement;
    }
    return null;
  }

  /**
   * @param Item $item
   * @param UploadTreeDao $uploadTreeView
   * @return int
   */
  protected function getItemIndex(Item $item, UploadTreeDao $uploadTreeView)
  {
    if ($item->getParentId() === null)
    {
      return 0;
    } else
    {
      $uploadTreeViewQuery = $uploadTreeView->asCTE();

      $sql = "$uploadTreeViewQuery
    select row_number from (
      select
        row_number() over (order by ufile_name),
        uploadtree_pk
      from uploadTreeView where parent=$1
    ) as index where uploadtree_pk=$2";

      $result = $this->dbManager->getSingleRow($sql, array($item->getParentId(), $item->getId()), __METHOD__ . "_current_offset" . $uploadTreeViewQuery);

      return intval($result['row_number']) - 1;
    }
  }

  /**
   * @param int $parent
   * @param UploadTreeDao $uploadTreeView
   * @return int
   */
  protected function getParentSize($parent, UploadTreeDao $uploadTreeView)
  {
    if ($parent === null)
    {
      return 1;
    }
    else
    {
      $uploadTreeViewQuery = $uploadTreeView->asCTE();
      $result = $this->dbManager->getSingleRow("$uploadTreeViewQuery
                      select count(*) from uploadTreeView where parent=$1",
          array($parent), __METHOD__ . "_current_count");
      return intval($result['count']);
    }
  }

  /**
   * @param int $parent
   * @param int $targetOffset
   * @param UploadTreeDao $uploadTreeView
   * @return Item
   */
  protected function getNewItemByIndex($parent, $targetOffset, UploadTreeDao $uploadTreeView)
  {
    if ($targetOffset < 0) {
      return null;
    }
    $uploadTreeViewQuery = $uploadTreeView->asCTE();

    $statementName = __METHOD__;
    $theQuery = "$uploadTreeViewQuery
      SELECT *
        from uploadTreeView
        where parent=$1
        order by ufile_name offset $2 limit 1";

    $newItemResult = $this->dbManager->getSingleRow($theQuery
        , array($parent, $targetOffset), $statementName);

    return $newItemResult ? $this->createItem($newItemResult, $uploadTreeView->getUploadTreeTableName()) : null;
  }


  /**
   * @param $uploadId
   * @return mixed
   */
  public function getUploadParent($uploadId)
  {
    $uploadTreeTableName = GetUploadtreeTableName($uploadId);
    $statementname = __METHOD__ . $uploadTreeTableName;

    $parent = $this->dbManager->getSingleRow(
        "select uploadtree_pk
            from $uploadTreeTableName
            where upload_fk=$1 and lft=1", array($uploadId), $statementname);
    return $parent['uploadtree_pk'];
  }


  public function getLeftAndRight($uploadtreeID, $uploadTreeTableName = "uploadtree")
  {
    $statementName = __METHOD__ . $uploadTreeTableName;
    $leftRight = $this->dbManager->getSingleRow(
        "SELECT lft,rgt FROM $uploadTreeTableName WHERE uploadtree_pk = $1",
        array($uploadtreeID), $statementName
    );

    return array($leftRight['lft'], $leftRight['rgt']);
  }

  /**
   * @var ItemTreeBounds $itemTreeBounds
   * @param $uploadTreeView
   * @return int
   */
  public function getContainingFileCount(ItemTreeBounds $itemTreeBounds, UploadTreeDao $uploadTreeView)
  {
    $sql = "SELECT count(*) FROM ". $uploadTreeView->getDbViewName() ." where lft BETWEEN $1 and $2";
    $result = $this->dbManager->getSingleRow($sql
        , array($itemTreeBounds->getLeft(), $itemTreeBounds->getRight()), __METHOD__ . $uploadTreeView->asCTE() );
    $output = $result['count'];
    return $output;
  }

  /**
   * @param int $uploadId
   * @param int $reusedUploadId
   */
  public function addReusedUpload($uploadId, $reusedUploadId)
  {
    $statementName = __METHOD__;

    $this->dbManager->prepare($statementName,
        "INSERT INTO upload_reuse (upload_fk, reused_upload_fk) VALUES($1, $2)");
    $res = $this->dbManager->execute($statementName, array($uploadId, $reusedUploadId));
    $this->dbManager->freeResult($res);
  }


  /**
   * @param array $uploadEntry
   * @param string $uploadTreeTableName
   * @return Item
   */
  protected function createItem($uploadEntry, $uploadTreeTableName)
  {
    $itemTreeBounds = new ItemTreeBounds(
        intval($uploadEntry['uploadtree_pk']),
        $uploadTreeTableName,
        intval($uploadEntry['upload_fk']),
        intval($uploadEntry['lft']), intval($uploadEntry['rgt']));

    $parent = $uploadEntry['parent'];
    $item = new Item(
        $itemTreeBounds, $parent !== null ? intval($parent) : null, intval($uploadEntry['pfile_fk']), intval($uploadEntry['ufile_mode']), $uploadEntry['ufile_name']
    );
    return $item;
  }

  /**
   * @param array $uploadEntryData
   * @param string $uploadTreeTableName
   * @throws Exception
   * @return ItemTreeBounds
   */
  protected function createItemTreeBounds($uploadEntryData, $uploadTreeTableName)
  {
    if ($uploadEntryData === FALSE)
    {
      throw new Exception("did not find uploadTreeId in $uploadTreeTableName");
    }
    return new ItemTreeBounds(intval($uploadEntryData['uploadtree_pk']), $uploadTreeTableName, intval($uploadEntryData['upload_fk']), intval($uploadEntryData['lft']), intval($uploadEntryData['rgt']));
  }
}