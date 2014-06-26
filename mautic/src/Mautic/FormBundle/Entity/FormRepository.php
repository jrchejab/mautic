<?php
/**
 * @package     Mautic
 * @copyright   2014 Mautic, NP. All rights reserved.
 * @author      Mautic
 * @link        http://mautic.com
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace Mautic\FormBundle\Entity;

use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Mautic\CoreBundle\Entity\CommonRepository;

/**
 * FormRepository
 */
class FormRepository extends CommonRepository
{

    /**
     * @param      $alias
     * @param null $id
     * @return mixed
     * @throws \Doctrine\ORM\NoResultException
     * @throws \Doctrine\ORM\NonUniqueResultException
     * @throws \Doctrine\ORM\ORMException
     */
    public function checkUniqueAlias($alias, $id = null)
    {
        $q = $this->createQueryBuilder('f')
            ->select('count(f.id) as aliasCount')
            ->where('f.alias = :alias');
        $q->setParameter('alias', $alias);

        if (!empty($id)) {
            $q->andWhere('f.id != :id');
            $q->setParameter('id', $id);
        }

        $results = $q->getQuery()->getSingleResult();
        return $results['aliasCount'];
    }


    /**
     * Get a list of entities
     *
     * @param array      $args
     * @return Paginator
     */
    public function getEntities($args = array())
    {
        $q = $this
            ->createQueryBuilder('f')
            ->select('f');

        if (!$this->buildClauses($q, $args)) {
            return array();
        }

        $query = $q->getQuery();

        if (isset($args['hydration_mode'])) {
            $mode = strtoupper($args['hydration_mode']);
            $query->setHydrationMode(constant("Query::$mode"));
        }

        $results = new Paginator($query);

        //use getIterator() here so that the first lead can be extracted without duplicating queries or looping through
        //them twice
        $iterator = $results->getIterator();

        if (!empty($args['getTotalCount'])) {
            //get the total count from paginator
            $totalItems = count($results);

            $iterator['totalCount'] = $totalItems;
        }
        return $iterator;
    }


    /**
     * @param QueryBuilder $q
     * @param              $filter
     * @return array
     */
    protected function addCatchAllWhereClause(QueryBuilder &$q, $filter)
    {
        $unique  = $this->generateRandomParameterName(); //ensure that the string has a unique parameter identifier
        $string  = ($filter->strict) ? $filter->string : "%{$filter->string}%";

        $expr = $q->expr()->orX(
            $q->expr()->like('f.name',  ':'.$unique),
            $q->expr()->like('f.description',  ':'.$unique)
        );

        if ($filter->not) {
            $q->expr()->not($expr);
        }

        return array(
            $expr,
            array("$unique" => $string)
        );
    }

    /**
     * @param QueryBuilder $q
     * @param              $filter
     * @return array
     */
    protected function addSearchCommandWhereClause(QueryBuilder &$q, $filter)
    {
        $command         = $field = $filter->command;
        $string          = $filter->string;
        $unique          = $this->generateRandomParameterName();
        $returnParameter = true; //returning a parameter that is not used will lead to a Doctrine error
        $expr            = false;
        switch ($command) {
            case $this->translator->trans('mautic.core.searchcommand.is'):
                switch($string) {
                    case $this->translator->trans('mautic.core.searchcommand.ispublished'):
                        $expr = $q->expr()->eq("f.isPublished", 1);
                        break;
                    case $this->translator->trans('mautic.core.searchcommand.isunpublished'):
                        $expr = $q->expr()->eq("f.isPublished", 0);
                        break;
                    case $this->translator->trans('mautic.core.searchcommand.ismine'):
                        $expr = $q->expr()->eq("f.createdBy", $this->currentUser->getId());
                        break;
                    case $this->translator->trans('mautic.form.form.searchcommand.isexpired'):
                        $expr = $q->expr()->andX(
                            $q->expr()->eq('f.isPublished', 1),
                            $q->expr()->isNotNull('f.publishDown'),
                            $q->expr()->neq('f.publishDown', $q->expr()->literal('')),
                            $q->expr()->lt('f.publishDown', 'CURRENT_TIMESTAMP()')
                        );
                        break;
                    case $this->translator->trans('mautic.form.form.searchcommand.ispending'):
                        $expr = $q->expr()->andX(
                            $q->expr()->eq('f.isPublished', 1),
                            $q->expr()->isNotNull('f.publishUp'),
                            $q->expr()->neq('f.publishUp', $q->expr()->literal('')),
                            $q->expr()->gt('f.publishUp', 'CURRENT_TIMESTAMP()')
                        );
                        break;
                }
                $returnParameter = false;
                break;
            case $this->translator->trans('mautic.core.searchcommand.has'):
                switch ($string) {
                    case $this->translator->trans('mautic.form.form.searchcommand.hasresults'):
                        $sq = $this->getEntityManager()->createQueryBuilder();
                        $subquery = $sq->select("count(s.id)")
                            ->from('MauticFormBundle:Submission', 's')
                            ->leftJoin('MauticFormBundle:Form', 'f2',
                                Join::WITH,
                                $sq->expr()->eq('s.form', "f2")
                            )
                            ->where(
                                $q->expr()->eq('s.form', 'f')
                            )
                            ->getDql();
                        $expr = $q->expr()->gt(sprintf("(%s)",$subquery), 1);
                        break;
                }
                $returnParameter = false;
                break;
            case $this->translator->trans('mautic.core.searchcommand.name'):
                $q->expr()->like('f.name', ':'.$unique);
                break;
        }

        $string  = ($filter->strict) ? $filter->string : "%{$filter->string}%";
        if ($expr && $filter->not) {
            $expr = $q->expr()->not($expr);
        }
        return array(
            $expr,
            ($returnParameter) ? array("$unique" => $string) : array()
        );
    }

    /**
     * @return array
     */
    public function getSearchCommands()
    {
        return array(
            'mautic.core.searchcommand.is' => array(
                'mautic.core.searchcommand.ispublished',
                'mautic.core.searchcommand.isunpublished',
                'mautic.core.searchcommand.ismine',
                'mautic.form.form.searchcommand.isexpired',
                'mautic.form.form.searchcommand.ispending'
            ),
            'mautic.core.searchcommand.has' => array(
                'mautic.form.form.searchcommand.hasresults'
            ),
            'mautic.core.searchcommand.name',
        );

    }

    /**
     * @return string
     */
    protected function getDefaultOrder()
    {
        return array(
            array('f.name', 'ASC')
        );
    }
}
