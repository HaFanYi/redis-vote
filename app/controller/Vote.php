<?php
namespace app\controller;

use common\constant\RedisConstant;
use common\util\SysUtil;
use think\Controller;


class Vote extends Controller {

    /**
     * 获取文章信息
     */
    public function get_articles() {
        $page = $this->request->param('page');
        $redis = SysUtil::getRedis();
        $order = "score:";
        $start = ($page - 1) * RedisConstant::ARTICLES_PRE_PAGE;
        $end = $start + RedisConstant::ARTICLES_PRE_PAGE - 1;
        $ids = $redis->zRevRange($order,$start, $end);
        $articles = [];
        foreach ($ids as $val) {
            $article_data = $redis->hGetAll($val);
            $article_data['id'] = $val;
            array_push($articles, $article_data);
        }
        echo json_encode($articles);
    }

    /**
     * 投票
     */
    public function vote() {
        $redis = SysUtil::getRedis();
        $user = $this->request->param('user');
        $article = $this->request->param('article');
        $votes = $this->request->param('votes');
        $votes = $votes == 1 ? 1: -1; //支持投反对票 1：支持票 -1：反对票
        $score = $votes == 1 ? RedisConstant::VOTE_SCORE : -RedisConstant::VOTE_SCORE;
        $cutOff = time() - RedisConstant::ONE_WEEK_IN_SECONDS;
        if ($redis->zScore('time:', $article) < $cutOff) {
            echo "expired";
            die;
        }
        $article_id = explode(':',$article)[1];
        if ($redis->sAdd("voted:".$article_id, $user)) {
            $redis->zIncrBy("score:", $score,$article);
            $redis->hIncrBy($article, 'votes', $votes);
            echo "success";
        }else {
            echo "failure";
        }

    }


    /**
     * 发布文章
     */
    public function poster() {
        $title = $this->request->param('title');
        $user = $this->request->param('user');
        $link = $this->request->param('link');
        $redis = SysUtil::getRedis();
        $article_id = strtolower($redis->incr("article:"));
        $voted = "voted:".$article_id;
        $redis->sAdd($voted, $user);
        $redis->expire($voted,RedisConstant::ONE_WEEK_IN_SECONDS);

        $now = time();
        $article = "article:".$article_id;
        $redis->hMSet($article, ["title"=>$title,"link"=>$link,"poster"=>$user,"time"=>$now,"votes"=>1]);
        $redis->zAdd('score:', ($now+RedisConstant::VOTE_SCORE), $article);
        $redis->zAdd("time:", $now, $article);
        echo $article_id;
    }

    /**
     * 添加分组
     */
    public function add_remove_groups() {
        $redis = SysUtil::getRedis();
        $article_id = $this->request->param('article_id');
        $to_add = $this->request->param('add_groups');
        $to_remove = $this->request->param('remove_groups');
        $article = "article:".$article_id;
        foreach ($to_add as $group) {
            $redis->sAdd("group:".$group, $article);
        }
        foreach ($to_remove as $g) {
            $redis->srem('group:'.$g, $article);
        }
    }

    /**
     * 获取分组文章
     */
    public function get_groups_article() {
        $order = "score:";
        $group = $this->request->param('group');
        $page = $this->request->param('page', 1);
        $key = $order.$group;
        $redis = SysUtil::getRedis();
        if (!$redis->exists($key)) {
            $redis->zInterStore($key, ["group:".$group, $order],[1, 0], 'MAX');
            $redis->expire($key, 60);
        }
        return $this->get_poster($page, $key);
    }
}
