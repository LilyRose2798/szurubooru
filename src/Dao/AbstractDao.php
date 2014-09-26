<?php
namespace Szurubooru\Dao;

abstract class AbstractDao implements ICrudDao
{
	protected $pdo;
	protected $fpdo;
	protected $tableName;
	protected $entityConverter;

	public function __construct(
		\Szurubooru\DatabaseConnection $databaseConnection,
		$tableName,
		\Szurubooru\Dao\EntityConverters\IEntityConverter $entityConverter)
	{
		$this->setDatabaseConnection($databaseConnection);
		$this->tableName = $tableName;
		$this->entityConverter = $entityConverter;
		$this->entityConverter->setEntityDecorator(function($entity)
			{
				$this->afterLoad($entity);
			});
	}

	public function setDatabaseConnection(\Szurubooru\DatabaseConnection $databaseConnection)
	{
		$this->pdo = $databaseConnection->getPDO();
		$this->fpdo = new \FluentPDO($this->pdo);
	}

	public function getTableName()
	{
		return $this->tableName;
	}

	public function getEntityConverter()
	{
		return $this->entityConverter;
	}

	public function save(&$entity)
	{
		if ($entity->getId())
		{
			$entity = $this->update($entity);
		}
		else
		{
			$entity = $this->create($entity);
		}
		$this->afterSave($entity);
		return $entity;
	}

	public function findAll()
	{
		$query = $this->fpdo->from($this->tableName);
		$arrayEntities = iterator_to_array($query);
		return $this->arrayToEntities($arrayEntities);
	}

	public function findById($entityId)
	{
		return $this->findOneBy($this->getIdColumn(), $entityId);
	}

	public function findByIds($entityIds)
	{
		return $this->findBy($this->getIdColumn(), $entityIds);
	}

	public function findFiltered(\Szurubooru\SearchServices\Filters\IFilter $searchFilter)
	{
		$query = $this->fpdo->from($this->tableName);

		$orderByString = self::compileOrderBy($searchFilter->getOrder());
		if ($orderByString)
			$query->orderBy($orderByString);

		$this->decorateQueryFromFilter($query, $searchFilter);
		if ($searchFilter->getPageSize() > 0)
		{
			$query->limit($searchFilter->getPageSize());
			$query->offset($searchFilter->getPageSize() * ($searchFilter->getPageNumber() - 1));
		}
		$entities = $this->arrayToEntities(iterator_to_array($query));

		$query = $this->fpdo->from($this->tableName);
		$this->decorateQueryFromFilter($query, $searchFilter);
		$totalRecords = count($query);

		$searchResult = new \Szurubooru\SearchServices\Result();
		$searchResult->setSearchFilter($searchFilter);
		$searchResult->setEntities($entities);
		$searchResult->setTotalRecords($totalRecords);
		$searchResult->setPageNumber($searchFilter->getPageNumber());
		$searchResult->setPageSize($searchFilter->getPageSize());
		return $searchResult;
	}

	public function deleteAll()
	{
		foreach ($this->findAll() as $entity)
		{
			$this->beforeDelete($entity);
		}
		$this->fpdo->deleteFrom($this->tableName)->execute();
	}

	public function deleteById($entityId)
	{
		return $this->deleteBy($this->getIdColumn(), $entityId);
	}

	protected function update(\Szurubooru\Entities\Entity $entity)
	{
		$arrayEntity = $this->entityConverter->toArray($entity);
		$this->fpdo->update($this->tableName)->set($arrayEntity)->where($this->getIdColumn(), $entity->getId())->execute();
		return $entity;
	}

	protected function create(\Szurubooru\Entities\Entity $entity)
	{
		$arrayEntity = $this->entityConverter->toArray($entity);
		$this->fpdo->insertInto($this->tableName)->values($arrayEntity)->execute();
		$entity->setId(intval($this->pdo->lastInsertId()));
		return $entity;
	}

	protected function getIdColumn()
	{
		return 'id';
	}

	protected function hasAnyRecords()
	{
		return count(iterator_to_array($this->fpdo->from($this->tableName)->limit(1))) > 0;
	}

	protected function findBy($columnName, $value)
	{
		$entities = [];
		$query = $this->fpdo->from($this->tableName)->where($columnName, $value);
		foreach ($query as $arrayEntity)
		{
			$entity = $this->entityConverter->toEntity($arrayEntity);
			$entities[$entity->getId()] = $entity;
		}
		return $entities;
	}

	protected function findOneBy($columnName, $value)
	{
		$arrayEntities = $this->findBy($columnName, $value);
		if (!$arrayEntities)
			return null;
		return array_shift($arrayEntities);
	}

	protected function deleteBy($columnName, $value)
	{
		foreach ($this->findBy($columnName, $value) as $entity)
		{
			$this->beforeDelete($entity);
		}
		$this->fpdo->deleteFrom($this->tableName)->where($columnName, $value)->execute();
	}

	protected function afterLoad(\Szurubooru\Entities\Entity $entity)
	{
	}

	protected function afterSave(\Szurubooru\Entities\Entity $entity)
	{
	}

	protected function beforeDelete(\Szurubooru\Entities\Entity $entity)
	{
	}

	protected function arrayToEntities(array $arrayEntities)
	{
		$entities = [];
		foreach ($arrayEntities as $arrayEntity)
		{
			$entity = $this->entityConverter->toEntity($arrayEntity);
			$entities[$entity->getId()] = $entity;
		}
		return $entities;
	}

	private function decorateQueryFromFilter($query, \Szurubooru\SearchServices\Filters\IFilter $filter)
	{
		foreach ($filter->getRequirements() as $requirement)
		{
			if ($requirement->isNegated())
				$query->where('NOT ' . $requirement->getType(), $requirement->getValue());
			else
				$query->where($requirement->getType(), $requirement->getValue());
		}
	}

	private static function compileOrderBy($order)
	{
		$orderByString = '';
		foreach ($order as $orderColumn => $orderDir)
			$orderByString .= $orderColumn . ' ' . ($orderDir === \Szurubooru\SearchServices\Filters\IFilter::ORDER_DESC ? 'DESC' : 'ASC') . ', ';
		return substr($orderByString, 0, -2);
	}
}
